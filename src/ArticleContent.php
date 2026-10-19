<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;

/** Per-post TekstTV rules shared by the playout and RSS; callers handle post type, status, password and query-level selection. */
final class ArticleContent
{
    /** @var array<int, true> Core never caches absent posts; skip them until the next prime_images(). */
    private static array $missing_images = [];

    /**
     * Prime the attachments and category image meta that active posts can show, in a fixed
     * number of queries.
     * The article query already primes post metadata and category relationships.
     *
     * @param array<mixed> $posts
     */
    public static function prime_images(array $posts): void
    {
        self::$missing_images = [];
        $image_ids = [];
        $term_ids = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || get_post_meta($post->ID, '_teksttv_active', true) !== '1') {
                continue;
            }
            $image_ids[] = Helpers::has_feature('sidebar_image') ? get_post_meta($post->ID, '_teksttv_sidebar_image', true) : 0;
            array_push($image_ids, self::thumbnail_id($post->ID), ...self::extra_image_ids($post->ID));
            array_push($term_ids, ...self::category_ids($post->ID));
        }

        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if ($term_ids !== []) {
            // Join core's lazy queue so one query loads these and other queued terms.
            wp_lazyload_term_meta($term_ids);
            foreach ($term_ids as $term_id) {
                $image_ids[] = get_term_meta($term_id, '_teksttv_category_image', true);
            }
        }
        $image_ids = array_values(array_unique(array_map('intval', array_filter($image_ids, fn($id) => is_numeric($id) && (int) $id > 0))));
        if ($image_ids !== []) {
            _prime_post_caches($image_ids, false, true);
            // A cache miss is not proof of absence (suspended cache additions), and image
            // filters can resolve IDs without a post, so only skip IDs the database lacks.
            $uncached = _get_non_cached_ids($image_ids, 'posts');
            if ($uncached !== [] && !self::image_filters_active()) {
                $existing = get_posts([
                    'post_type' => 'attachment',
                    'post_status' => array_keys(get_post_stati()),
                    'post__in' => $uncached,
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                ]);
                self::$missing_images = array_fill_keys(array_diff($uncached, $existing), true);
            }
        }
    }

    /**
     * Null means inactive, too old, unscheduled, malformed or empty; the title keeps HTML entities (RSS decodes them).
     *
     * @return array{title: string, pages: list<string>, sidebar_image: array<string, string>|null, images: list<array<string, string>>}|null
     */
    public static function prepare(\WP_Post $post): ?array
    {
        if (get_post_meta($post->ID, '_teksttv_active', true) !== '1') {
            return null;
        }

        // An absolute instant, shared with the RSS validator, so a repeated DST hour cannot revive a post.
        $expires = RecentPostsQuery::expires_at($post);
        if ($expires !== null && $expires <= current_datetime()->getTimestamp()) {
            return null;
        }

        if (Helpers::has_feature('scheduling')) {
            $start = self::string_meta($post->ID, '_teksttv_date_start');
            $end = self::string_meta($post->ID, '_teksttv_date_end');
            $days = get_post_meta($post->ID, '_teksttv_days', true);
            // Authoritative and stricter than the playout's SQL pre-filter: is_within_date_range() ignores
            // unparseable dates and rolls over impossible ones, is_allowed_on_day() cannot stringify non-scalar
            // days. '' days is missing meta (all days); any other non-array is malformed.
            if (
                !self::valid_date($start) || !self::valid_date($end)
                || (is_array($days) ? array_filter($days, 'is_scalar') !== $days : $days !== '')
                || !Helpers::is_block_scheduled(['date_start' => $start, 'date_end' => $end, 'days' => $days])
            ) {
                return null;
            }
        }

        $content = self::string_meta($post->ID, '_teksttv_content');
        $override = Helpers::has_feature('custom_title') ? self::string_meta($post->ID, '_teksttv_title') : '';
        if ($content === null || $override === null) {
            return null;
        }

        $pages = self::split_pages($content);
        $images = [];
        foreach (self::extra_image_ids($post->ID) as $attachment_id) {
            $image = self::image_data($attachment_id, 'image_slide');
            // A URL filter can intentionally suppress an image.
            if (!empty($image['url'])) {
                $images[] = $image;
            }
        }

        if ($pages === [] && $images === []) {
            return null;
        }

        $sidebar = $pages !== [] ? self::get_sidebar_image_data($post->ID) : null;
        return [
            'title' => $override ?: get_the_title($post),
            'pages' => $pages,
            'sidebar_image' => !empty($sidebar['url']) ? $sidebar : null,
            'images' => $images,
        ];
    }

    /** @return list<mixed> Saved order; string keys from corrupt meta are dropped. */
    private static function extra_image_ids(int $post_id): array
    {
        $ids = Helpers::has_feature('extra_images') ? get_post_meta($post_id, '_teksttv_images', true) : [];
        return is_array($ids) ? array_values($ids) : [];
    }

    private static function string_meta(int $post_id, string $key): ?string
    {
        $value = get_post_meta($post_id, $key, true);
        return is_string($value) ? $value : null;
    }

    /** Empty or a Y-m-d date that sanitizing leaves unchanged. */
    private static function valid_date(?string $date): bool
    {
        return $date !== null && Helpers::sanitize_date_input($date) === $date;
    }

    /**
     * Split content into non-empty pages.
     *
     * @return list<string>
     */
    public static function split_pages(string $content): array
    {
        $parts = Helpers::has_feature('page_separator') ? preg_split('/<p[^>]*>\s*-{3,}\s*<\/p>|(?:^|\R)[ \t]*-{3,}[ \t]*(?=\R|$)/i', $content) : [$content];
        return array_values(array_filter(array_map('trim', $parts ?: []), fn($page) => $page !== ''));
    }

    /**
     * Override ('0' suppresses), primary category, categories, featured image.
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
            $data = self::image_data($override_id, 'text_sidebar');
            if ($data) {
                return $data;
            }
        }

        foreach (self::category_ids($post_id) as $term_id) {
            $data = self::image_data(get_term_meta($term_id, '_teksttv_category_image', true), 'text_sidebar');
            if ($data) {
                return $data;
            }
        }

        return self::image_data(self::thumbnail_id($post_id), 'text_sidebar');
    }

    /** @return \Generator<int, int> Primary category first; lazy, so a primary image skips get_the_terms(). */
    private static function category_ids(int $post_id): \Generator
    {
        $primary = apply_filters('teksttv_primary_category', get_post_meta($post_id, '_yoast_wpseo_primary_category', true), $post_id);
        if (is_numeric($primary) && $primary) {
            yield (int) $primary;
        }
        // Reuse the object-term cache primed by the caller's WP_Query.
        $categories = get_the_terms($post_id, 'category');
        foreach (is_array($categories) ? $categories : [] as $category) {
            yield (int) $category->term_id;
        }
    }

    private static function thumbnail_id(int $post_id): int
    {
        // Core's (int) cast turns a corrupt array or object into attachment 1; objects also warn.
        $raw = get_post_meta($post_id, '_thumbnail_id', true);
        return is_array($raw) || is_object($raw) ? 0 : (int) get_post_thumbnail_id($post_id);
    }

    /**
     * Corrupt (non-numeric) and empty IDs from meta have no image.
     *
     * @return array<string, string>|null
     */
    private static function image_data(mixed $id, string $slot): ?array
    {
        if (!is_numeric($id) || !$id) {
            return null;
        }
        // Filters added after priming (e.g. during rss2_head) may still resolve a missing ID.
        return isset(self::$missing_images[(int) $id]) && !self::image_filters_active() ? null : Helpers::get_image_data((int) $id, 'large', $slot);
    }

    private static function image_filters_active(): bool
    {
        return has_filter('image_downsize') || has_filter('wp_get_attachment_image_src');
    }
}
