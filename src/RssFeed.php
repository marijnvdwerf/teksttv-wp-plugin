<?php

namespace TekstTV;

use TekstTV\Blocks\Common\RecentPostsQuery;
use TekstTV\Blocks\Loop\ArticlesLoopBlock;

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
