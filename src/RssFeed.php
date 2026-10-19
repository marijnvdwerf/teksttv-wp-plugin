<?php

namespace TekstTV;

final class RssFeed
{
    public const XML_NAMESPACE = 'https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0';

    private static bool $namespace_rendered = false;

    public static function init(): void
    {
        add_action('rss2_ns', [self::class, 'render_namespace']);
        add_action('rss2_item', [self::class, 'render_item']);
        // Run last so the decision sees the final feed query.
        add_action('pre_get_posts', [self::class, 'prepare_request'], PHP_INT_MAX);
        add_filter('wp_headers', [self::class, 'filter_headers']);
    }

    /**
     * Drop the conditional request headers so core cannot answer 304.
     *
     * Core compares them with the last post modification before wp_headers runs.
     * That date misses date/weekday transitions and changes that do not save a
     * post, such as settings and category images.
     */
    public static function prepare_request(\WP_Query $query): void
    {
        if (
            $query->is_main_query() && $query->is_feed('rss2') && !$query->is_comment_feed
            && Helpers::has_feature('rss_feed')
        ) {
            unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
        }
    }

    /**
     * Send no-cache headers and drop the ETag, which wp_get_nocache_headers() keeps.
     *
     * @param array<string, string|false> $headers
     * @return array<string, string|false>
     */
    public static function filter_headers(array $headers): array
    {
        if (is_feed('rss2') && !is_comment_feed() && Helpers::has_feature('rss_feed')) {
            $headers = array_merge($headers, wp_get_nocache_headers());
            unset($headers['ETag']);
        }

        return $headers;
    }

    public static function render_namespace(): void
    {
        // Comment feeds also fire rss2_ns.
        if (Helpers::has_feature('rss_feed') && !is_comment_feed()) {
            echo ' xmlns:teksttv="' . esc_url(self::XML_NAMESPACE) . '"';
            self::$namespace_rendered = true;
            global $wp_query;
            if ($wp_query instanceof \WP_Query && is_array($wp_query->posts)) {
                ArticleContent::prime_images(array_values(array_filter($wp_query->posts, fn($post) => $post instanceof \WP_Post)));
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
