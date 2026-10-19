<?php

namespace TekstTV\Blocks\Loop;

use TekstTV\AdminPage;
use TekstTV\BlockRegistry;
use TekstTV\Blocks\BuildContext;
use TekstTV\Blocks\Common\DurationField;
use TekstTV\Blocks\Common\RecentPostsQuery;
use TekstTV\Blocks\Common\TaxonomyFilters;
use TekstTV\Helpers;
use WP_Query;

final class ArticlesLoopBlock
{
    /** Cap backfill queries when runtime filters reject candidates. */
    private const MAX_QUERY_BATCHES = 10;

    public static function register(): void
    {
        BlockRegistry::register('articles', [
            'label' => 'Artikelen',
            'icon' => 'admin-post',
            'color' => '#2271b1',
            'context' => 'loop',
            'render' => [self::class, 'render_fields'],
            'save' => [self::class, 'save'],
            'build' => [self::class, 'build'],
        ]);
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function render_fields(int|string $index, array $block, string $prefix): void
    {
        $count = $block['count'] ?? 3;
        $default_text = (int) get_option('teksttv_duration_text', Helpers::DURATION_DEFAULTS['teksttv_duration_text']);
        $default_image = (int) get_option('teksttv_duration_image', Helpers::DURATION_DEFAULTS['teksttv_duration_image']);

        ?>
        <?php AdminPage::render_block_section_start('Inhoud', 'Welke artikelen komen in de loop?', 'content'); ?>
        <div class="teksttv-field-grid teksttv-field-grid--selection">
            <div class="teksttv-field teksttv-field--compact">
                <label <?php Helpers::field_for($prefix, $index, 'count'); ?>><?php echo esc_html('Aantal'); ?></label>
                <input type="number" <?php Helpers::field_attrs($prefix, $index, 'count'); ?> value="<?php echo esc_attr((string) $count); ?>" min="1" max="50" class="small-text" data-summary="%sx" />
            </div>
            <?php TaxonomyFilters::render_selects($index, (array) ($block['taxonomy_filters'] ?? []), $prefix); ?>
        </div>
        <?php AdminPage::render_block_section_end(); ?>
        <?php AdminPage::render_block_section_start('Weergaveduur', 'Leeg laten gebruikt de standaardinstelling.', 'duration'); ?>
        <div class="teksttv-field-grid teksttv-field-grid--duration">
            <?php
            DurationField::render($prefix, $index, 'duration_text', 'Tekst', (string) ($block['duration_text'] ?? ''), $default_text);
            DurationField::render($prefix, $index, 'duration_image', 'Afbeelding', (string) ($block['duration_image'] ?? ''), $default_image);
            ?>
        </div>
        <?php AdminPage::render_block_section_end(); ?>
        <?php
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function save(array $raw): array
    {
        $saved = [
            'count' => Helpers::clamp_int($raw['count'] ?? 3, 1, 50),
            'taxonomy_filters' => TaxonomyFilters::sanitize_from_post($raw),
        ];

        $dt = $raw['duration_text'] ?? '';
        $di = $raw['duration_image'] ?? '';
        if ($dt !== '') {
            $saved['duration_text'] = Helpers::clamp_int($dt, Helpers::DURATION_MIN_SECONDS, Helpers::DURATION_MAX_SECONDS);
        }
        if ($di !== '') {
            $saved['duration_image'] = Helpers::clamp_int($di, Helpers::DURATION_MIN_SECONDS, Helpers::DURATION_MAX_SECONDS);
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>
     */
    public static function build(array $block, string $channel = ''): array
    {
        $slides = [];
        // Clamp legacy values that predate save-time validation.
        $count = Helpers::clamp_int($block['count'] ?? 3, 1, 50);
        $taxonomy_filters = $block['taxonomy_filters'] ?? [];

        // Disabled features ignore their retained post meta.
        $scheduling = Helpers::has_feature('scheduling');
        $custom_title = Helpers::has_feature('custom_title');
        $extra_images = Helpers::has_feature('extra_images');

        $meta_query = [
            'relation' => 'AND',
            ['key' => '_teksttv_active', 'value' => '1', 'compare' => '='],
        ];
        if ($scheduling) {
            $meta_query[] = Helpers::get_date_range_meta_query();
        }

        $text_duration = Helpers::duration_ms($block['duration_text'] ?? null, 'teksttv_duration_text');
        $image_duration = Helpers::duration_ms($block['duration_image'] ?? null, 'teksttv_duration_image');
        $batch_size = max(10, $count);
        // Keep post__not_in fixed so pagination does not grow SQL/cache keys.
        $seen_post_ids = BuildContext::get_seen_post_ids();
        $emitted_post_count = 0;

        for ($batch = 0; $batch < self::MAX_QUERY_BATCHES && $emitted_post_count < $count; $batch++) {
            $query = new WP_Query(RecentPostsQuery::args($batch_size, $taxonomy_filters, [
                'meta_query' => $meta_query,
                'post__not_in' => $seen_post_ids,
                'paged' => $batch + 1,
                'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
                'ignore_sticky_posts' => true,
            ]));

            while ($emitted_post_count < $count && $query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                if ($scheduling) {
                    $days = Helpers::normalize_days(get_post_meta($post_id, '_teksttv_days', true));
                    if (!Helpers::is_allowed_on_day($days)) {
                        continue;
                    }
                }

                $post_slides = [];
                $content = get_post_meta($post_id, '_teksttv_content', true);

                if (!empty($content)) {
                    $title_override = $custom_title ? get_post_meta($post_id, '_teksttv_title', true) : '';
                    $title = !empty($title_override) ? $title_override : get_the_title();
                    $sidebar_image = self::get_sidebar_image_data($post_id);
                    $pages = self::split_pages($content);
                    foreach ($pages as $page_content) {
                        $slide = [
                            'type' => 'text',
                            'duration' => $text_duration,
                            'title' => $title,
                            'body' => wpautop($page_content),
                        ];

                        if (!empty($sidebar_image)) {
                            $slide['image'] = $sidebar_image;
                        }

                        $post_slides[] = $slide;
                    }
                }

                $images = $extra_images ? get_post_meta($post_id, '_teksttv_images', true) : [];
                if (!empty($images) && is_array($images)) {
                    foreach ($images as $attachment_id) {
                        $image_data = Helpers::get_image_data((int) $attachment_id, 'large', 'image_slide');
                        if ($image_data) {
                            $post_slides[] = array_merge([
                                'type' => 'image',
                                'duration' => $image_duration,
                            ], $image_data);
                        }
                    }
                }

                if ($post_slides === []) {
                    continue;
                }

                array_push($slides, ...$post_slides);
                BuildContext::mark_post_seen((int) $post_id);
                $emitted_post_count++;
            }

            wp_reset_postdata();
            if (count($query->posts) < $batch_size) {
                break;
            }
        }

        return $slides;
    }

    /**
     * Split content into non-empty pages.
     *
     * @return list<string>
     */
    public static function split_pages(string $content): array
    {
        if (!Helpers::has_feature('page_separator')) {
            $trimmed = trim($content);
            return $trimmed !== '' ? [$trimmed] : [];
        }

        $parts = preg_split('/<p[^>]*>\s*-{3,}\s*<\/p>|(?:^|\R)[ \t]*-{3,}[ \t]*(?=\R|$)/i', $content);
        $pages = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $pages[] = $part;
            }
        }
        return $pages;
    }

    /**
     * Get sidebar image data for a post.
     *
     * @return array<string, string>|null
     */
    public static function get_sidebar_image_data(int $post_id): ?array
    {
        // A disabled feature ignores its stored override, including '0'.
        if (Helpers::has_feature('sidebar_image')) {
            $override_id = get_post_meta($post_id, '_teksttv_sidebar_image', true);
            if ($override_id === '0' || $override_id === 0) {
                return null;
            }
            // Corrupt meta (arrays, objects) counts as no override.
            if (is_numeric($override_id) && $override_id) {
                $data = Helpers::get_image_data((int) $override_id, 'large', 'text_sidebar');
                if ($data) {
                    return $data;
                }
            }
        }

        /** @var int|string|false $primary_term_id */
        $primary_term_id = apply_filters('teksttv_primary_category', get_post_meta($post_id, '_yoast_wpseo_primary_category', true), $post_id);
        if (is_numeric($primary_term_id) && $primary_term_id) {
            $data = self::get_category_image_data((int) $primary_term_id);
            if ($data) {
                return $data;
            }
        }

        // Reuse the object-term cache primed by the articles query.
        $categories = get_the_terms($post_id, 'category');
        foreach (is_array($categories) ? $categories : [] as $cat) {
            $data = self::get_category_image_data((int) $cat->term_id);
            if ($data) {
                return $data;
            }
        }

        // Core casts _thumbnail_id to int, which warns for corrupt arrays and objects.
        $raw_thumb_id = get_post_meta($post_id, '_thumbnail_id', true);
        $thumb_id = is_array($raw_thumb_id) || is_object($raw_thumb_id) ? 0 : get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            return Helpers::get_image_data((int) $thumb_id, 'large', 'text_sidebar');
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function get_category_image_data(int $term_id): ?array
    {
        $image_id = get_term_meta($term_id, '_teksttv_category_image', true);
        if (!is_numeric($image_id) || !$image_id) {
            return null;
        }

        return Helpers::get_image_data((int) $image_id, 'large', 'text_sidebar');
    }
}
