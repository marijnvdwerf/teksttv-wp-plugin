<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;

/** Per-post TekstTV rules shared by the playout and RSS; callers handle post type, status, password and query-level selection. */
final class ArticleContent
{
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
        $attachment_ids = Helpers::has_feature('extra_images') ? get_post_meta($post->ID, '_teksttv_images', true) : [];
        foreach (is_array($attachment_ids) ? $attachment_ids : [] as $attachment_id) {
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
            $data = self::image_data($override_id, 'text_sidebar');
            if ($data) {
                return $data;
            }
        }

        $data = self::get_category_image_data(apply_filters('teksttv_primary_category', get_post_meta($post_id, '_yoast_wpseo_primary_category', true), $post_id));
        if ($data) {
            return $data;
        }

        // Reuse the object-term cache primed by the caller's WP_Query.
        $categories = get_the_terms($post_id, 'category');
        foreach (is_array($categories) ? $categories : [] as $cat) {
            $data = self::get_category_image_data($cat->term_id);
            if ($data) {
                return $data;
            }
        }

        // Core's (int) cast turns a corrupt array or object into attachment 1; objects also warn.
        $raw_thumb_id = get_post_meta($post_id, '_thumbnail_id', true);
        return is_array($raw_thumb_id) || is_object($raw_thumb_id) ? null : self::image_data(get_post_thumbnail_id($post_id), 'text_sidebar');
    }

    /** @return array<string, string>|null */
    private static function get_category_image_data(mixed $term_id): ?array
    {
        return is_numeric($term_id) && $term_id ? self::image_data(get_term_meta((int) $term_id, '_teksttv_category_image', true), 'text_sidebar') : null;
    }

    /**
     * Corrupt (non-numeric) and empty IDs from meta have no image.
     *
     * @return array<string, string>|null
     */
    private static function image_data(mixed $id, string $slot): ?array
    {
        return is_numeric($id) && $id ? Helpers::get_image_data((int) $id, 'large', $slot) : null;
    }
}
