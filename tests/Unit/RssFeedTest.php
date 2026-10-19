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
        (new \ReflectionProperty(RssFeed::class, 'changed_blogs'))->setValue(null, []);
        Functions\when('get_current_blog_id')->justReturn(1);
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
        Functions\when('TekstTV\\time')->justReturn(strtotime('2026-10-19 10:00:00 GMT'));
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Amsterdam'));
        Functions\when('get_post_datetime')->alias(fn($post) => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $post->post_date, new \DateTimeZone('Europe/Amsterdam')));
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
        Functions\when('get_option')->alias(fn($key, $default = false) => $key === 'teksttv_rss_modified' ? strtotime('2026-10-18 10:00:00 GMT') : $default);
        $this->assertSame('2026-10-18 10:00:00', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
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

    public function test_other_feeds_and_non_gmt_timezones_keep_core_validator(): void
    {
        Functions\when('is_feed')->justReturn(false);
        $this->assertSame('original', RssFeed::last_modified('original', 'GMT'));
        Functions\when('is_feed')->justReturn(true);
        $this->assertSame('original', RssFeed::last_modified('original', 'blog'));
        Functions\when('is_comment_feed')->justReturn(true);
        $this->assertSame('original', RssFeed::last_modified('original', 'GMT'));
    }

    public function test_invalidation_writes_once_at_shutdown_past_core(): void
    {
        Functions\expect('update_option')->never();
        RssFeed::invalidate();
        $this->assertSame(PHP_INT_MAX, has_action('shutdown', [RssFeed::class, 'save_revision']));

        \Mockery::resetContainer();
        Functions\when('get_lastpostmodified')->justReturn('2026-10-19 10:01:00');
        Functions\expect('update_option')->once()->with('teksttv_rss_modified', strtotime('2026-10-19 10:01:01 GMT'), false)->andReturn(true);
        RssFeed::save_revision();
    }

    public function test_revision_goes_past_the_current_second_after_older_writes(): void
    {
        Functions\when('get_lastpostmodified')->justReturn('2026-10-01 12:00:00');
        Functions\expect('update_option')->once()->with('teksttv_rss_modified', strtotime('2026-10-19 10:00:01 GMT'), false)->andReturn(true);
        RssFeed::invalidate();
        RssFeed::save_revision();
    }

    /** @return array<string, array{string, string}> */
    public static function servedSeconds(): array
    {
        return [
            'after a post save in the same second' => ['2026-10-19 10:00:00', '2026-10-19 10:00:00'],
            'at local midnight' => ['2026-10-18 22:00:00', '2026-10-01 12:00:00'],
        ];
    }

    /** A response, then a write in that same second, must not leave conditional requests matching. */
    #[DataProvider('servedSeconds')]
    public function test_write_in_the_served_second_advances_the_validator(string $now_gmt, string $core_gmt): void
    {
        $stored = 0;
        $now = strtotime($now_gmt . ' GMT');
        Functions\when('TekstTV\\time')->justReturn($now);
        Functions\when('current_datetime')->justReturn((new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Amsterdam')));
        Functions\when('is_feed')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) use (&$stored) {
            return match ($key) {
                'teksttv_features' => $this->features,
                'teksttv_max_post_age' => $this->max_age,
                'teksttv_rss_modified' => $stored,
                default => $default,
            };
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$stored) {
            $stored = $value;
            return true;
        });
        Functions\when('get_lastpostmodified')->justReturn($core_gmt);

        $served = RssFeed::last_modified($core_gmt, 'GMT');
        $this->assertSame($now_gmt, $served);
        RssFeed::invalidate();
        RssFeed::save_revision();
        $this->assertGreaterThan($served, RssFeed::last_modified($core_gmt, 'GMT'));
    }

    public function test_shutdown_writes_the_revision_of_each_changed_site(): void
    {
        $current = 2;
        Functions\when('get_current_blog_id')->alias(function () use (&$current) {
            return $current;
        });
        RssFeed::invalidate();
        $current = 1;
        RssFeed::invalidate();

        $written = [];
        Functions\when('get_lastpostmodified')->justReturn(false);
        Functions\when('switch_to_blog')->alias(function ($id) use (&$current) {
            $current = $id;
            return true;
        });
        Functions\when('restore_current_blog')->alias(function () use (&$current) {
            $current = 1;
            return true;
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$current, &$written) {
            $written[] = $current;
            return true;
        });
        RssFeed::save_revision();
        $this->assertSame([2, 1], $written);
        $this->assertSame(1, $current);
    }

    /** @return array<string, array{string, string, string, bool}> */
    public static function transitions(): array
    {
        return [
            'update of a published post' => ['post', 'publish', 'publish', true],
            'publication' => ['post', 'publish', 'draft', true],
            'unpublication' => ['post', 'trash', 'publish', true],
            'draft autosave' => ['post', 'draft', 'draft', false],
            'revision' => ['revision', 'inherit', 'new', false],
            'published page' => ['page', 'publish', 'publish', false],
            'force delete of a published post' => ['post', 'delete', 'publish', true],
            'force delete of a draft' => ['post', 'delete', 'draft', false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_post_transitions_invalidate_only_for_published_posts(string $type, string $new, string $old, bool $invalidates): void
    {
        $this->post->post_type = $type;
        RssFeed::post_transitioned($new, $old, $this->post);
        $this->assertSame($invalidates, has_action('shutdown', [RssFeed::class, 'save_revision']) !== false);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function termChanges(): array
    {
        return [
            'category of a published post' => ['category', 'publish', true],
            'tag of a published post' => ['post_tag', 'publish', true],
            'category of a draft' => ['category', 'draft', false],
            'other taxonomy' => ['region', 'publish', false],
        ];
    }

    #[DataProvider('termChanges')]
    public function test_term_changes_of_published_posts_invalidate(string $taxonomy, string $status, bool $invalidates): void
    {
        Functions\when('get_post_type')->justReturn('post');
        Functions\when('get_post_status')->justReturn($status);
        RssFeed::terms_changed(42, $taxonomy);
        $this->assertSame($invalidates, has_action('shutdown', [RssFeed::class, 'save_revision']) !== false);
    }

    public function test_force_deletes_are_hooked(): void
    {
        RssFeed::init();
        $this->assertNotFalse(has_action('before_delete_post'));
    }

    public function test_feed_options_are_hooked_but_unrelated_ones_are_not(): void
    {
        RssFeed::init();
        foreach (['teksttv_features', 'teksttv_max_post_age', 'timezone_string', 'gmt_offset'] as $option) {
            foreach (['add_option_', 'update_option_', 'delete_option_'] as $prefix) {
                $this->assertNotFalse(has_action($prefix . $option, [RssFeed::class, 'invalidate']), $prefix . $option);
            }
        }
        $this->assertFalse(has_action('update_option_blogname', [RssFeed::class, 'invalidate']));
    }

    /** @return array<string, array{string, bool}> */
    public static function metaKeys(): array
    {
        return [
            'TekstTV field' => ['_teksttv_title', true],
            'featured image' => ['_thumbnail_id', true],
            'primary category' => ['_yoast_wpseo_primary_category', true],
            'attachment metadata' => ['_wp_attachment_metadata', true],
            'attachment file' => ['_wp_attached_file', true],
            'category image' => ['_teksttv_category_image', true],
            'AI suggestion' => ['_teksttv_ai_title', false],
            'unrelated' => ['_edit_lock', false],
        ];
    }

    #[DataProvider('metaKeys')]
    public function test_meta_changes_invalidate_only_for_exported_keys(string $key, bool $invalidates): void
    {
        RssFeed::meta_changed(['7'], 42, $key);
        $this->assertSame($invalidates, has_action('shutdown', [RssFeed::class, 'save_revision']) !== false);
    }
}
