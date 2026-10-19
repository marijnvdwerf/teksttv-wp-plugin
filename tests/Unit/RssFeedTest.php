<?php

namespace TekstTV\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TekstTV\Helpers;
use TekstTV\RssFeed;

class RssFeedTest extends TestCase
{
    /** @var list<string> */
    private array $features;

    /** @var array<string, mixed> */
    private array $meta;

    private \WP_Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->features = [...Helpers::DEFAULT_FEATURES, 'rss_feed'];
        $this->meta = ['_teksttv_active' => '1', '_teksttv_content' => 'Eerste slide'];
        $this->post = self::makePost();
        $this->post->post_type = 'post';
        $this->post->post_status = 'publish';
        $this->post->post_password = '';

        Functions\when('get_option')->alias(fn($key, $default = false) => $key === 'teksttv_features' ? $this->features : $default);
        Functions\when('get_post')->alias(fn() => $this->post);
        Functions\when('get_post_meta')->alias(fn($id, $key) => $this->meta[$key] ?? '');
        Functions\when('get_the_title')->justReturn('Gewone &amp; titel &lpar;regio&rpar;');
        Functions\when('is_comment_feed')->justReturn(false);
        Functions\when('wp_check_invalid_utf8')->returnArg();
        Functions\when('esc_xml')->alias(fn($value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8', false));
        Functions\when('esc_url')->returnArg();
        Functions\when('wpautop')->alias(fn($value) => '<p>' . $value . '</p>');
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('Europe/Amsterdam')));
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Amsterdam'));
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_thumbnail_id')->justReturn(false);
        Functions\when('apply_filters')->alias(fn($hook, $value) => $value);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => $id === 999 ? false : 'https://example.com/' . $id . '.jpg?a=1&b=2');
        Functions\when('wp_get_attachment_caption')->justReturn('');
    }

    private function renderItem(): \SimpleXMLElement
    {
        ob_start();
        try {
            RssFeed::render_item();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return new \SimpleXMLElement('<item xmlns:teksttv="' . RssFeed::XML_NAMESPACE . '">' . $output . '</item>');
    }

    public function test_export_is_disabled_by_default(): void
    {
        $this->features = Helpers::DEFAULT_FEATURES;
        $this->assertCount(0, $this->renderItem()->children(RssFeed::XML_NAMESPACE));
    }

    public function test_exports_custom_title_and_xml_safe_html(): void
    {
        $this->meta['_teksttv_title'] = 'Kop & <nieuws> café 📰';
        $this->meta['_teksttv_content'] = "Tekst &amp; opmaak ]]> blijft\x01 behouden";
        $fields = $this->renderItem()->children(RssFeed::XML_NAMESPACE);
        $this->assertSame('Kop & <nieuws> café 📰', (string) $fields->title);
        $this->assertSame('<p>Tekst &amp; opmaak ]]> blijft behouden</p>', (string) $fields->content);
    }

    public function test_falls_back_to_decoded_post_title(): void
    {
        $this->assertSame('Gewone & titel (regio)', (string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->title);
    }

    public function test_disabled_title_feature_ignores_stored_override(): void
    {
        $this->features = ['rss_feed'];
        $this->meta['_teksttv_title'] = 'Verborgen kop';
        $this->assertSame('Gewone & titel (regio)', (string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->title);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function excludedMeta(): iterable
    {
        yield 'inactive' => ['_teksttv_active', '0'];
        yield 'future start' => ['_teksttv_date_start', '2026-10-20'];
        yield 'expired end' => ['_teksttv_date_end', '2026-10-18'];
        yield 'wrong weekday' => ['_teksttv_days', ['2', '3']];
        yield 'empty content' => ['_teksttv_content', " \n\t"];
        yield 'only page separators' => ['_teksttv_content', "---\n---"];
    }

    #[DataProvider('excludedMeta')]
    public function test_ineligible_content_has_no_teksttv_fields(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
        $this->assertCount(0, $this->renderItem()->children(RssFeed::XML_NAMESPACE));
    }

    public function test_disabled_scheduling_ignores_retained_dates_and_days(): void
    {
        $this->features = ['rss_feed'];
        $this->meta['_teksttv_date_start'] = '2099-01-01';
        $this->meta['_teksttv_days'] = [];
        $this->assertNotEmpty((string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->content);
    }

    /** @return iterable<string, array{string, string}> */
    public static function protectedPosts(): iterable
    {
        yield 'draft' => ['post_status', 'draft'];
        yield 'private' => ['post_status', 'private'];
        yield 'password protected' => ['post_password', 'secret'];
        yield 'page' => ['post_type', 'page'];
    }

    #[DataProvider('protectedPosts')]
    public function test_non_public_posts_have_no_teksttv_fields(string $key, string $value): void
    {
        $this->post->$key = $value;
        $this->assertCount(0, $this->renderItem()->children(RssFeed::XML_NAMESPACE));
    }

    public function test_pages_are_joined_with_html_boundaries(): void
    {
        $this->meta['_teksttv_content'] = "Eerste\n---\nTweede";
        $this->assertSame("<p>Eerste</p>\n<hr />\n<p>Tweede</p>", (string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->content);
    }

    public function test_exports_sidebar_and_extra_images_in_order_skipping_missing_attachments(): void
    {
        $this->meta['_teksttv_sidebar_image'] = '10';
        $this->meta['_teksttv_images'] = [12, 999, 11];
        $fields = $this->renderItem()->children(RssFeed::XML_NAMESPACE);
        $this->assertSame('https://example.com/10.jpg?a=1&b=2', (string) $fields->image);
        $this->assertSame(['https://example.com/12.jpg?a=1&b=2', 'https://example.com/11.jpg?a=1&b=2'], array_map('strval', iterator_to_array($fields->extraImage, false)));
    }

    public function test_disabled_image_features_ignore_stored_overrides(): void
    {
        $this->features = ['rss_feed'];
        $this->meta['_teksttv_sidebar_image'] = '10';
        $this->meta['_teksttv_images'] = [11];
        Functions\when('get_post_thumbnail_id')->justReturn(12);
        $fields = $this->renderItem()->children(RssFeed::XML_NAMESPACE);
        $this->assertSame('https://example.com/12.jpg?a=1&b=2', (string) $fields->image);
        $this->assertCount(0, $fields->extraImage);
    }

    public function test_image_only_posts_export_usable_images(): void
    {
        $this->meta['_teksttv_content'] = '';
        $this->meta['_teksttv_images'] = [11];
        $fields = $this->renderItem()->children(RssFeed::XML_NAMESPACE);
        $this->assertCount(1, $fields->extraImage);
        $this->assertCount(0, $fields->content);
        $this->assertCount(0, $fields->image);
    }

    public function test_image_only_post_without_usable_images_is_omitted(): void
    {
        $this->meta['_teksttv_content'] = '';
        $this->meta['_teksttv_images'] = [999];
        $this->assertCount(0, $this->renderItem()->children(RssFeed::XML_NAMESPACE));
    }

    public function test_comment_feed_has_no_teksttv_namespace(): void
    {
        Functions\when('is_comment_feed')->justReturn(true);
        $this->expectOutputString('');
        RssFeed::render_namespace();
    }

    public function test_namespace_is_only_registered_when_enabled(): void
    {
        $this->features = Helpers::DEFAULT_FEATURES;
        $this->expectOutputString('');
        RssFeed::render_namespace();
    }

    public function test_enabled_feed_has_no_stale_cache_validators(): void
    {
        Functions\when('is_feed')->justReturn(true);
        Functions\when('wp_get_nocache_headers')->justReturn(['Cache-Control' => 'no-cache, no-store, must-revalidate', 'Last-Modified' => false, 'ETag' => '"filtered"']);
        $headers = RssFeed::filter_headers(['ETag' => '"old"', 'Last-Modified' => 'old', 'Content-Type' => 'application/rss+xml']);
        $this->assertArrayNotHasKey('ETag', $headers);
        $this->assertFalse($headers['Last-Modified']);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertSame('application/rss+xml', $headers['Content-Type']);
    }

    public function test_disabled_feature_keeps_original_cache_headers(): void
    {
        $this->features = Helpers::DEFAULT_FEATURES;
        Functions\when('is_feed')->justReturn(true);
        $headers = ['ETag' => '"original"', 'Last-Modified' => 'original'];
        $this->assertSame($headers, RssFeed::filter_headers($headers));
    }

    public function test_other_feed_types_keep_original_cache_headers(): void
    {
        Functions\when('is_feed')->justReturn(false);
        $headers = ['ETag' => '"original"'];
        $this->assertSame($headers, RssFeed::filter_headers($headers));
    }
}
