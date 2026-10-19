<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;

final class RssFeed
{
    public const XML_NAMESPACE = 'https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0';

    private static bool $namespace_rendered = false;

    public static function init(): void
    {
        add_action('rss2_ns', [self::class, 'render_namespace']);
        add_action('rss2_item', [self::class, 'render_item']);
        add_filter('get_lastpostmodified', [self::class, 'last_modified'], 10, 2);
        // A value supplied by another plugin skips the filter above.
        add_filter('pre_get_lastpostmodified', static fn($pre, $timezone) => is_string($pre) ? self::last_modified($pre, $timezone) : $pre, PHP_INT_MAX, 2);
        add_filter('wp_headers', [self::class, 'feed_headers']);
        foreach (['teksttv_features', 'teksttv_max_post_age', 'timezone_string', 'gmt_offset', 'posts_per_rss'] as $option) {
            foreach (['add_option_', 'update_option_', 'delete_option_'] as $prefix) {
                add_action($prefix . $option, [self::class, 'invalidate']);
            }
        }
        foreach (['added', 'updated', 'deleted'] as $action) {
            add_action($action . '_post_meta', [self::class, 'post_meta_changed'], 10, 3);
            add_action($action . '_term_meta', [self::class, 'meta_changed'], 10, 3);
        }
        add_action('transition_post_status', [self::class, 'post_transitioned'], 10, 3);
        // Force deletes fire no status transition; invalidate once the row is gone.
        add_action('after_delete_post', static fn($post_id, $post) => self::post_transitioned('delete', $post->post_status, $post), 10, 2);
        // Term assignments can change feeds without saving the post.
        add_action('set_object_terms', static fn($post_id, $terms, $tt_ids, $taxonomy) => self::terms_changed((int) $post_id, $taxonomy), 10, 4);
        add_action('deleted_term_relationships', static fn($post_id, $tt_ids, $taxonomy) => self::terms_changed((int) $post_id, $taxonomy), 10, 3);
        // Category names order the sidebar image fallback.
        add_action('edited_category', [self::class, 'invalidate']);
    }

    /**
     * Extends core's GMT date for both feed validators.
     */
    public static function last_modified(string|false $modified, string $timezone): string|false
    {
        if (strtolower($timezone) !== 'gmt' || !is_feed('rss2') || is_comment_feed()) {
            return $modified;
        }

        // Retain the revision while disabled: turning export off changes the XML too.
        $timestamp = max($modified ? (int) strtotime($modified . ' GMT') : 0, (int) get_option('teksttv_rss_modified', 0));
        $now = current_datetime();
        if (Helpers::has_feature('rss_feed')) {
            $timestamp = max($timestamp, $now->setTime(0, 0)->getTimestamp());
            // Age eligibility can expire within the day, without a post save.
            global $wp_query;
            foreach ($wp_query instanceof \WP_Query ? $wp_query->posts ?? [] : [] as $post) {
                $expiry = $post instanceof \WP_Post ? RecentPostsQuery::expires_at($post) : null;
                if ($expiry !== null && $expiry <= $now->getTimestamp()) {
                    $timestamp = max($timestamp, $expiry);
                }
            }
        }
        // Cap at now and request start so future revisions and concurrent writes remain detectable.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- server-set request start, cast to int.
        $timestamp = min($timestamp, $now->getTimestamp(), (int) ($_SERVER['REQUEST_TIME'] ?? PHP_INT_MAX));
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : $modified;
    }

    /** @param int|array<int|string> $meta_ids */
    public static function meta_changed(int|array $meta_ids, int $object_id, string $key): void
    {
        if (self::tracked_key($key)) {
            self::invalidate();
        }
    }

    /**
     * Drafts invalidate on publication; ID 0 means delete_post_meta_by_key().
     *
     * @param int|array<int|string> $meta_ids
     */
    public static function post_meta_changed(int|array $meta_ids, int $object_id, string $key): void
    {
        if (self::tracked_key($key) && ($object_id === 0 || get_post_type($object_id) === 'attachment' || self::in_feed($object_id))) {
            self::invalidate();
        }
    }

    private static function tracked_key(string $key): bool
    {
        return (str_starts_with($key, '_teksttv_') && !str_starts_with($key, '_teksttv_ai_')) || in_array($key, ['_thumbnail_id', '_yoast_wpseo_primary_category', '_wp_attachment_metadata', '_wp_attached_file'], true);
    }

    /** Saved and unpublished posts; core's date only covers posts that remain published. */
    public static function post_transitioned(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($post->post_type === 'post' && ($new_status === 'publish' || $old_status === 'publish')) {
            self::invalidate();
        }
    }

    public static function terms_changed(int $post_id, string $taxonomy): void
    {
        if (in_array($taxonomy, ['category', 'post_tag'], true) && self::in_feed($post_id)) {
            self::invalidate();
        }
    }

    private static function in_feed(int $post_id): bool
    {
        return get_post_type($post_id) === 'post' && get_post_status($post_id) === 'publish';
    }

    /**
     * Keeps the revision ahead of now to invalidate same-second responses.
     */
    public static function invalidate(): void
    {
        update_option('teksttv_rss_modified', max(time() + 1, (int) get_option('teksttv_rss_modified', 0)), false);
    }

    /**
     * Requires revalidation; validators alone permit heuristic freshness.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function feed_headers(array $headers): array
    {
        $cache = $headers['Cache-Control'] ?? '';
        if (is_feed('rss2') && !is_comment_feed() && Helpers::has_feature('rss_feed') && !preg_match('/(^|,)\s*no-(cache|store)\s*(,|$)/i', $cache)) {
            $headers['Cache-Control'] = ltrim($cache . ', no-cache', ', ');
        }
        return $headers;
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
        // RSS is public; the playout deliberately also accepts password-protected posts.
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || $post->post_password !== '') {
            return;
        }

        $article = ArticleContent::prepare($post);
        if ($article === null) {
            return;
        }
        self::render_element('title', html_entity_decode($article['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($article['pages'] !== []) {
            self::render_element('content', implode("\n<hr />\n", array_map('wpautop', $article['pages'])), true);
        }
        if ($article['sidebar_image'] !== null) {
            self::render_element('image', $article['sidebar_image']['url']);
        }
        foreach ($article['images'] as $image) {
            self::render_element('extraImage', $image['url']);
        }
    }

    /** Emits XML 1.0 text with invalid characters removed and CDATA terminators split. */
    private static function render_element(string $name, string $value, bool $cdata = false): void
    {
        $value = wp_check_invalid_utf8($value, true);
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        $encoded = $cdata ? '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>' : htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal element names; XML-escaped text or safely split CDATA above.
        echo "\t\t<teksttv:" . $name . '>' . $encoded . '</teksttv:' . $name . ">\n";
    }
}
