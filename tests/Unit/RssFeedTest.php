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

    private int $max_age = 30;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(RssFeed::class, 'namespace_rendered'))->setValue(null, false);
        $this->features = [...Helpers::DEFAULT_FEATURES, 'rss_feed'];
        $this->meta = ['_teksttv_active' => '1', '_teksttv_content' => 'Eerste slide'];
        $this->post = self::makePost();
        $this->post->post_type = 'post';
        $this->post->post_status = 'publish';
        $this->post->post_password = '';
        $this->post->post_date = '2026-10-19 11:00:00';

        Functions\when('get_option')->alias(fn($key, $default = false) => match ($key) {
            'teksttv_features' => $this->features,
            'teksttv_max_post_age' => $this->max_age,
            default => $default,
        });
        Functions\when('get_post')->alias(fn() => $this->post);
        Functions\when('get_post_meta')->alias(fn($id, $key) => $this->meta[$key] ?? '');
        Functions\when('get_the_title')->justReturn('Gewone &amp; titel &lpar;regio&rpar;');
        Functions\when('is_comment_feed')->justReturn(false);
        Functions\when('wp_check_invalid_utf8')->alias(fn($value, $strip = false) => $strip ? mb_scrub($value, 'UTF-8') : $value);
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
            RssFeed::render_namespace();
            ob_clean();
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

    public function test_invalid_utf8_is_scrubbed_instead_of_emptying_fields(): void
    {
        $this->meta['_teksttv_title'] = "Caf\xC3 bezoek";
        $this->assertStringContainsString('bezoek', (string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->title);
    }

    public function test_title_is_decoded_only_once(): void
    {
        $this->meta['_teksttv_title'] = 'Toon &amp;lt;tag&amp;gt; en &amp;amp;';
        $this->assertSame('Toon &lt;tag&gt; en &amp;', (string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->title);
    }

    public function test_falls_back_to_decoded_post_title(): void
    {
        $this->assertSame('Gewone & titel (regio)', (string) $this->renderItem()->children(RssFeed::XML_NAMESPACE)->title);
    }

    public function test_inactive_post_has_no_teksttv_fields(): void
    {
        $this->meta['_teksttv_active'] = '0';
        $this->assertCount(0, $this->renderItem()->children(RssFeed::XML_NAMESPACE));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ineligiblePosts(): iterable
    {
        yield 'draft' => ['post_status', 'draft'];
        yield 'private' => ['post_status', 'private'];
        yield 'password protected' => ['post_password', 'secret'];
        yield 'page' => ['post_type', 'page'];
    }

    #[DataProvider('ineligiblePosts')]
    public function test_ineligible_posts_have_no_teksttv_fields(string $key, string $value): void
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

    public function test_image_only_posts_export_usable_images(): void
    {
        $this->meta['_teksttv_content'] = '';
        $this->meta['_teksttv_images'] = [11];
        $fields = $this->renderItem()->children(RssFeed::XML_NAMESPACE);
        $this->assertCount(1, $fields->extraImage);
        $this->assertCount(0, $fields->content);
        $this->assertCount(0, $fields->image);
    }

    public function test_comment_feed_has_no_teksttv_fields_or_namespace(): void
    {
        Functions\when('is_comment_feed')->justReturn(true);
        $this->assertCount(0, $this->renderItem()->children(RssFeed::XML_NAMESPACE));
        $this->expectOutputString('');
        RssFeed::render_namespace();
    }

    public function test_fields_need_a_declared_namespace(): void
    {
        $this->expectOutputString('');
        RssFeed::render_item();
    }

    public function test_namespace_is_only_registered_when_enabled(): void
    {
        $this->features = Helpers::DEFAULT_FEATURES;
        $this->expectOutputString('');
        RssFeed::render_namespace();
    }

    public function test_enabled_feed_validator_includes_local_midnight(): void
    {
        Functions\when('is_feed')->justReturn(true);
        $this->assertSame('2026-10-18 22:00:00', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
        $this->assertSame('2026-10-19 09:00:00', RssFeed::last_modified('2026-10-19 09:00:00', 'GMT'));
    }

    public function test_disabled_feature_keeps_revision_to_invalidate_export_removal(): void
    {
        $this->features = Helpers::DEFAULT_FEATURES;
        Functions\when('is_feed')->justReturn(true);
        Functions\when('get_option')->alias(fn($key, $default = false) => $key === 'teksttv_rss_modified' ? strtotime('2026-10-19 10:00:00 GMT') : $default);
        $this->assertSame('2026-10-19 10:00:00', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
    }

    public function test_age_expiry_advances_the_validator_within_the_same_day(): void
    {
        Functions\when('is_feed')->justReturn(true);
        $query = \Mockery::mock('WP_Query');
        $this->post->post_date = '2026-09-19 11:59:59';
        $query->posts = [$this->post];
        $GLOBALS['wp_query'] = $query;
        try {
            Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2026-10-19 11:59:58', new \DateTimeZone('Europe/Amsterdam')));
            $this->assertSame('2026-10-18 22:00:00', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
            Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2026-10-19 11:59:59', new \DateTimeZone('Europe/Amsterdam')));
            $this->assertSame('2026-10-19 09:59:59', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
        } finally {
            unset($GLOBALS['wp_query']);
        }
    }

    public function test_other_feeds_and_timezone_formats_keep_core_validator(): void
    {
        Functions\when('is_feed')->justReturn(false);
        $this->assertSame('original', RssFeed::last_modified('original', 'GMT'));
        Functions\when('is_feed')->justReturn(true);
        Functions\when('is_comment_feed')->justReturn(true);
        $this->assertSame('original', RssFeed::last_modified('original', 'GMT'));
        $this->assertSame('original', RssFeed::last_modified('original', 'blog'));
    }

    public function test_invalidation_advances_past_core_and_runs_once_per_request(): void
    {
        (new \ReflectionProperty(RssFeed::class, 'invalidated'))->setValue(null, false);
        $future = time() + 60;
        Functions\when('get_lastpostmodified')->justReturn(gmdate('Y-m-d H:i:s', $future));
        Functions\expect('update_option')->once()->with('teksttv_rss_modified', $future + 1, false)->andReturn(true);
        RssFeed::invalidate();
        RssFeed::invalidate();
    }

    public function test_unrelated_option_and_meta_changes_do_not_invalidate(): void
    {
        (new \ReflectionProperty(RssFeed::class, 'invalidated'))->setValue(null, false);
        Functions\expect('update_option')->never();
        RssFeed::option_changed('unrelated');
        RssFeed::meta_changed(1, 42, 'unrelated');
    }
}
