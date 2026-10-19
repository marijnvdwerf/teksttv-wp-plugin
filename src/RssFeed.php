<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;

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
            // rss2_ns fires once before the item loop, so prime the feed's images here.
            global $wp_query;
            if ($wp_query instanceof \WP_Query && is_array($wp_query->posts)) {
                ArticleContent::prime_images($wp_query->posts);
            }
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
        ) {
            return;
        }

        // RSS is public; the playout deliberately also accepts password-protected posts.
        $article = ArticleContent::prepare($post);
        if ($article === null) {
            return;
        }
        self::render_element('title', html_entity_decode($article['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($article['pages'] !== []) {
            $body = implode("\n<hr />\n", array_map('wpautop', $article['pages']));
            self::render_element('content', $body, true);
            if ($article['sidebar_image'] !== null) {
                self::render_element('image', $article['sidebar_image']['url']);
            }
        }
        foreach ($article['images'] as $image) {
            self::render_element('extraImage', $image['url']);
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
