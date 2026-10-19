<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;
use TekstTV\Blocks\Loop\ArticlesLoopBlock;

final class RssFeed
{
    public const XML_NAMESPACE = 'https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0';

    private static bool $namespace_rendered = false;

    private static bool $invalidated = false;

    public static function init(): void
    {
        add_action('rss2_ns', [self::class, 'render_namespace']);
        add_action('rss2_item', [self::class, 'render_item']);
        add_filter('get_lastpostmodified', [self::class, 'last_modified'], 10, 2);
        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, [self::class, 'option_changed']);
        }
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta'] as $hook) {
            add_action($hook, [self::class, 'meta_changed'], 10, 3);
        }
        add_action('save_post', [self::class, 'invalidate']);
    }

    /**
     * Extend core's validators before it evaluates conditional requests.
     */
    public static function last_modified(string|false $modified, string $timezone): string|false
    {
        if (strtolower($timezone) !== 'gmt' || !is_feed('rss2') || is_comment_feed()) {
            return $modified;
        }

        // Retain the revision while disabled: turning export off changes the XML too.
        $timestamp = max($modified ? (strtotime($modified . ' GMT') ?: 0) : 0, (int) get_option('teksttv_rss_modified', 0));
        if (Helpers::has_feature('rss_feed')) {
            $now = current_datetime();
            $timestamp = max($timestamp, $now->setTime(0, 0)->getTimestamp());
            // Age eligibility can expire within the day, without a post save.
            global $wp_query;
            $max_age = RecentPostsQuery::max_age_days();
            if ($max_age > 0 && $wp_query instanceof \WP_Query) {
                foreach ($wp_query->posts as $post) {
                    if (!$post instanceof \WP_Post) {
                        continue;
                    }
                    $published = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $post->post_date, wp_timezone());
                    $expiry = $published ? $published->modify('+' . $max_age . ' days')->getTimestamp() : 0;
                    if ($expiry <= $now->getTimestamp()) {
                        $timestamp = max($timestamp, $expiry);
                    }
                }
            }
        }
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : $modified;
    }

    public static function option_changed(string $option): void
    {
        if (in_array($option, ['teksttv_features', 'teksttv_max_post_age', 'timezone_string', 'gmt_offset'], true)) {
            self::invalidate();
        }
    }

    /** @param int|list<int> $meta_ids */
    public static function meta_changed(int|array $meta_ids, int $object_id, string $key): void
    {
        if (str_starts_with($key, '_teksttv_') || in_array($key, ['_thumbnail_id', '_yoast_wpseo_primary_category', '_wp_attachment_metadata', '_wp_attached_file'], true)) {
            self::invalidate();
        }
    }

    public static function invalidate(): void
    {
        if (self::$invalidated) {
            return;
        }
        self::$invalidated = true;
        // HTTP dates have second precision. Advance even for writes in the same second.
        $modified = get_lastpostmodified('gmt');
        $previous = max((int) get_option('teksttv_rss_modified', 0), $modified ? (strtotime($modified . ' GMT') ?: 0) : 0);
        update_option('teksttv_rss_modified', max(time(), $previous + 1), false);
    }

    public static function render_namespace(): void
    {
        // Comment feeds also fire rss2_ns.
        if (Helpers::has_feature('rss_feed') && !is_comment_feed()) {
            echo ' xmlns:teksttv="' . esc_url(self::XML_NAMESPACE) . '"';
            self::$namespace_rendered = true;
        }
    }

    public static function render_item(): void
    {
        // Fields need the namespace, which custom feed templates may not declare.
        if (!self::$namespace_rendered) {
            return;
        }

        $post = get_post();
        if (
            !$post || $post->post_type !== 'post' || $post->post_status !== 'publish'
            || $post->post_password !== ''
            || get_post_meta($post->ID, '_teksttv_active', true) !== '1'
        ) {
            return;
        }

        // Same exclusive cutoff as the playout's date_query: calendar days in the site timezone.
        $max_age = RecentPostsQuery::max_age_days();
        if ($max_age > 0 && $post->post_date <= current_datetime()->modify('-' . $max_age . ' days')->format('Y-m-d H:i:s')) {
            return;
        }

        $scheduled = !Helpers::has_feature('scheduling') || Helpers::is_block_scheduled([
            'date_start' => (string) get_post_meta($post->ID, '_teksttv_date_start', true),
            'date_end' => (string) get_post_meta($post->ID, '_teksttv_date_end', true),
            'days' => get_post_meta($post->ID, '_teksttv_days', true),
        ]);
        if (!$scheduled) {
            return;
        }

        $pages = ArticlesLoopBlock::split_pages((string) get_post_meta($post->ID, '_teksttv_content', true));
        $images = [];
        $attachment_ids = Helpers::has_feature('extra_images') ? get_post_meta($post->ID, '_teksttv_images', true) : [];
        foreach (is_array($attachment_ids) ? $attachment_ids : [] as $attachment_id) {
            $image = Helpers::get_image_data((int) $attachment_id, 'large', 'image_slide');
            if (!empty($image['url'])) {
                $images[] = $image['url'];
            }
        }

        // Like the playout, image-only posts are exported.
        if ($pages === [] && $images === []) {
            return;
        }

        $override = Helpers::has_feature('custom_title') ? get_post_meta($post->ID, '_teksttv_title', true) : '';
        $title = !empty($override) ? (string) $override : get_the_title($post);
        self::render_element('title', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($pages !== []) {
            $body = implode("\n<hr />\n", array_map('wpautop', $pages));
            self::render_element('content', $body, true);
            $image = ArticlesLoopBlock::get_sidebar_image_data($post->ID);
            if (!empty($image['url'])) {
                self::render_element('image', $image['url']);
            }
        }

        foreach ($images as $url) {
            self::render_element('extraImage', $url);
        }
    }

    /** Emit plain text as XML 1.0: scrub invalid UTF-8, drop forbidden characters, then escape or wrap in split CDATA. */
    private static function render_element(string $name, string $value, bool $cdata = false): void
    {
        $value = wp_check_invalid_utf8($value, true);
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        $encoded = $cdata ? '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>' : htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal element names; XML-escaped text or safely split CDATA above.
        echo "\t\t<teksttv:" . $name . '>' . $encoded . '</teksttv:' . $name . ">\n";
    }
}
