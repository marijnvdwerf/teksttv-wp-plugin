<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;

/** Per-post TekstTV rules shared by the playout and RSS; callers choose which posts to expose. */
final class ArticleContent
{
    /** @var array<int, int> Sources resolved once per query batch, including filtered IDs. */
    private static array $primary_categories = [];

    /** @var array<int, int> */
    private static array $thumbnails = [];

    /** @var array<int, true> Missing attachments must not trigger a fresh lookup for each slide. */
    private static array $missing_images = [];

    /**
     * Prime all attachment posts and metadata referenced by a query in bounded batches.
     * The article query already primes post metadata and category relationships.
     *
     * @param list<\WP_Post> $posts
     */
    public static function prime_images(array $posts): void
    {
        self::$primary_categories = [];
        self::$thumbnails = [];
        self::$missing_images = [];
        if ($posts === []) {
            return;
        }

        $image_ids = [];
        $term_ids = [];
        foreach ($posts as $post) {
            if (Helpers::has_feature('sidebar_image')) {
                $image_ids[] = get_post_meta($post->ID, '_teksttv_sidebar_image', true);
            }
            self::$thumbnails[$post->ID] = self::thumbnail_id($post->ID);
            self::$primary_categories[$post->ID] = self::primary_category_id($post->ID);
            $image_ids[] = self::$thumbnails[$post->ID];
            $term_ids[] = self::$primary_categories[$post->ID];
            $categories = get_the_terms($post->ID, 'category');
            foreach (is_array($categories) ? $categories : [] as $category) {
                $term_ids[] = (int) $category->term_id;
            }
            if (Helpers::has_feature('extra_images')) {
                $extra = get_post_meta($post->ID, '_teksttv_images', true);
                array_push($image_ids, ...(is_array($extra) ? $extra : []));
            }
        }

        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if ($term_ids !== []) {
            update_meta_cache('term', $term_ids);
            foreach ($term_ids as $term_id) {
                $image_ids[] = get_term_meta($term_id, '_teksttv_category_image', true);
            }
        }
        $image_ids = array_values(array_unique(array_map('intval', array_filter($image_ids, fn($id) => is_numeric($id) && (int) $id > 0))));
        if ($image_ids !== []) {
            _prime_post_caches($image_ids, false, true);
            foreach ($image_ids as $id) {
                if (!wp_cache_get($id, 'posts')) {
                    self::$missing_images[$id] = true;
                }
            }
        }
    }

    /**
     * @return array{title: string, pages: list<string>, sidebar_image: array<string, string>|null, images: list<array<string, string>>}|null
     */
    public static function prepare(\WP_Post $post): ?array
    {
        if (get_post_meta($post->ID, '_teksttv_active', true) !== '1') {
            return null;
        }

        $max_age = RecentPostsQuery::max_age_days();
        if ($max_age > 0 && $post->post_date <= current_datetime()->modify('-' . $max_age . ' days')->format('Y-m-d H:i:s')) {
            return null;
        }

        if (Helpers::has_feature('scheduling')) {
            $start = self::string_meta($post->ID, '_teksttv_date_start');
            $end = self::string_meta($post->ID, '_teksttv_date_end');
            $days = get_post_meta($post->ID, '_teksttv_days', true);
            // Invalid stored dates are ineligible, consistently with the SQL pre-filter.
            if (
                !self::valid_date($start) || !self::valid_date($end)
                || (is_array($days) && array_filter($days, fn($day) => !is_scalar($day)) !== [])
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
            if (!is_numeric($attachment_id)) {
                continue;
            }
            $image = self::image_data((int) $attachment_id, 'image_slide');
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
            'title' => !empty($override) ? $override : get_the_title($post),
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

    private static function valid_date(?string $date): bool
    {
        if ($date === null || str_contains($date, "\0")) {
            return false;
        }
        if ($date === '') {
            return true;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
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
                $data = self::image_data((int) $override_id, 'text_sidebar');
                if ($data) {
                    return $data;
                }
            }
        }

        $primary_term_id = self::primary_category_id($post_id);
        if ($primary_term_id) {
            $data = self::get_category_image_data($primary_term_id);
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

        $thumb_id = self::thumbnail_id($post_id);
        if ($thumb_id) {
            return self::image_data($thumb_id, 'text_sidebar');
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

        return self::image_data((int) $image_id, 'text_sidebar');
    }

    private static function primary_category_id(int $post_id): int
    {
        if (isset(self::$primary_categories[$post_id])) {
            return self::$primary_categories[$post_id];
        }
        $id = apply_filters('teksttv_primary_category', get_post_meta($post_id, '_yoast_wpseo_primary_category', true), $post_id);
        return is_numeric($id) ? (int) $id : 0;
    }

    private static function thumbnail_id(int $post_id): int
    {
        if (isset(self::$thumbnails[$post_id])) {
            return self::$thumbnails[$post_id];
        }
        // Core casts _thumbnail_id to int, which warns for corrupt arrays and objects.
        $raw = get_post_meta($post_id, '_thumbnail_id', true);
        return is_array($raw) || is_object($raw) ? 0 : (int) get_post_thumbnail_id($post_id);
    }

    /** @return array<string, string>|null */
    private static function image_data(int $id, string $slot): ?array
    {
        return isset(self::$missing_images[$id]) ? null : Helpers::get_image_data($id, 'large', $slot);
    }
}
