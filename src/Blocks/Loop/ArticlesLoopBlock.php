<?php

namespace TekstTV\Blocks\Loop;

use TekstTV\AdminPage;
use TekstTV\ArticleContent;
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

        $meta_query = [
            'relation' => 'AND',
            ['key' => '_teksttv_active', 'value' => '1', 'compare' => '='],
        ];
        if (Helpers::has_feature('scheduling')) {
            // SQL pre-filter only; ArticleContent::prepare() is authoritative.
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

            ArticleContent::prime_images($query->posts);

            while ($emitted_post_count < $count && $query->have_posts()) {
                $query->the_post();
                $post = get_post();
                $article = $post ? ArticleContent::prepare($post) : null;
                if ($article === null) {
                    continue;
                }

                foreach ($article['pages'] as $page_content) {
                    $slide = [
                        'type' => 'text',
                        'duration' => $text_duration,
                        'title' => $article['title'],
                        'body' => wpautop($page_content),
                    ];
                    if ($article['sidebar_image'] !== null) {
                        $slide['image'] = $article['sidebar_image'];
                    }
                    $slides[] = $slide;
                }
                foreach ($article['images'] as $image) {
                    $slides[] = array_merge(['type' => 'image', 'duration' => $image_duration], $image);
                }

                BuildContext::mark_post_seen($post->ID);
                $emitted_post_count++;
            }

            wp_reset_postdata();
            if (count($query->posts) < $batch_size) {
                break;
            }
        }

        return $slides;
    }
}
