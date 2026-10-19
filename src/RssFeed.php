<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;

final class RssFeed
{
    public const XML_NAMESPACE = 'https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0';

    private static bool $namespace_rendered = false;

    /** @var array<int, true> Sites written to in this request, keyed by blog ID. */
    private static array $changed_blogs = [];

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
        // Force deletes fire no status transition.
        add_action('before_delete_post', static fn($post_id, $post) => self::post_transitioned('delete', $post->post_status, $post), 10, 2);
        // Term changes without a post save change the feed's categories and the sidebar image.
        add_action('set_object_terms', static fn($post_id, $terms, $tt_ids, $taxonomy) => self::terms_changed((int) $post_id, $taxonomy), 10, 4);
        add_action('deleted_term_relationships', static fn($post_id, $tt_ids, $taxonomy) => self::terms_changed((int) $post_id, $taxonomy), 10, 3);
        // Category names order the sidebar image fallback.
        add_action('edited_category', [self::class, 'invalidate']);
    }

    /**
     * Core derives feed Last-Modified and ETag from this value in WP::send_headers();
     * raise it for changes that do not save a published post.
     */
    public static function last_modified(string|false $modified, string $timezone): string|false
    {
        if (strtolower($timezone) !== 'gmt' || !is_feed('rss2') || is_comment_feed()) {
            return $modified;
        }

        // Retain the revision while disabled: turning export off changes the XML too.
        $timestamp = self::revision($modified);
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
        // Never in the future (RFC 9110): a revision ahead of the clock would absorb midnight and expiry.
        // Never past the request start either: a write after this request read its data gets a later revision.
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
     * Only attachments (feed images) and published posts matter; drafts reach the feed through
     * a publish transition. Object ID 0 is delete_post_meta_by_key().
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
     * Defer one advance to shutdown, after the request's last write: a feed fetched between writes
     * of one request (an editor save, an import) must not receive the validator of the final state.
     */
    public static function invalidate(): void
    {
        self::$changed_blogs[get_current_blog_id()] = true;
        add_action('shutdown', [self::class, 'save_revision'], PHP_INT_MAX);
    }

    public static function save_revision(): void
    {
        foreach (array_keys(self::$changed_blogs) as $blog_id) {
            // Only multisite has other blog IDs, so single sites never call switch_to_blog().
            $switched = $blog_id !== get_current_blog_id();
            if ($switched) {
                switch_to_blog($blog_id);
            }
            // HTTP dates have second precision: go past every validator served up to this second,
            // including midnight and expiry, so even writes within the same second advance it.
            update_option('teksttv_rss_modified', max(time(), self::revision(get_lastpostmodified('gmt'))) + 1, false);
            if ($switched) {
                restore_current_blog();
            }
        }
        self::$changed_blogs = [];
    }

    /** Later of core's GMT date and the stored revision, as a timestamp. */
    private static function revision(string|false $modified): int
    {
        return max($modified ? (int) strtotime($modified . ' GMT') : 0, (int) get_option('teksttv_rss_modified', 0));
    }

    /**
     * Validators alone allow heuristic freshness (RFC 9111), so make caches revalidate every poll.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function feed_headers(array $headers): array
    {
        if (is_feed('rss2') && !is_comment_feed() && Helpers::has_feature('rss_feed')) {
            $headers['Cache-Control'] ??= 'no-cache';
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
