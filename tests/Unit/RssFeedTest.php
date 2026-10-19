<?php

namespace TekstTV\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
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

    private int $revision = 0;

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_TIME']);
        parent::tearDown();
    }

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
            'teksttv_rss_modified' => $this->revision,
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
        $_SERVER['REQUEST_TIME'] = strtotime('2026-10-19 10:00:00 GMT');
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
        $this->revision = strtotime('2026-10-18 10:00:00 GMT');
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
        // An Atom feed: a feed, but not RSS 2.0.
        Functions\when('is_feed')->alias(fn($feeds = '') => $feeds !== 'rss2');
        $this->assertSame('original', RssFeed::last_modified('original', 'GMT'));
        Functions\when('is_feed')->justReturn(true);
        $this->assertSame('original', RssFeed::last_modified('original', 'blog'));
        Functions\when('is_comment_feed')->justReturn(true);
        $this->assertSame('original', RssFeed::last_modified('original', 'GMT'));
    }

    public function test_invalidation_writes_once_at_shutdown_past_core(): void
    {
        Functions\when('get_lastpostmodified')->justReturn('2026-10-19 10:01:00');
        Functions\expect('update_option')->once()->with('teksttv_rss_modified', strtotime('2026-10-19 10:01:01 GMT'), false)->andReturn(true);
        RssFeed::invalidate();
        RssFeed::invalidate();
        $this->assertSame(PHP_INT_MAX, has_action('shutdown', [RssFeed::class, 'save_revision']));
        RssFeed::save_revision();
    }

    /** @return array<string, array{string, string, string}> */
    public static function revisionWrites(): array
    {
        return [
            'after older writes' => ['2026-10-01 12:00:00', '', '2026-10-19 10:00:01'],
            'after a revision ahead of the clock' => ['2026-10-01 12:00:00', '2026-10-19 10:00:05', '2026-10-19 10:00:06'],
        ];
    }

    /** The revision never moves back, even after a burst left it ahead of the clock. */
    #[DataProvider('revisionWrites')]
    public function test_revision_goes_past_every_served_validator(string $core, string $stored, string $expected): void
    {
        $this->revision = $stored === '' ? 0 : strtotime($stored . ' GMT');
        Functions\when('get_lastpostmodified')->justReturn($core);
        Functions\expect('update_option')->once()->with('teksttv_rss_modified', strtotime($expected . ' GMT'), false)->andReturn(true);
        RssFeed::invalidate();
        RssFeed::save_revision();
    }

    /** @return array<string, array{string, string}> */
    public static function servedSeconds(): array
    {
        return [
            'after a post save in the same second' => ['2026-10-19 10:00:00', '2026-10-19 10:00:00'],
            'in the second before local midnight' => ['2026-10-18 21:59:59', '2026-10-01 12:00:00'],
            'at local midnight' => ['2026-10-18 22:00:00', '2026-10-01 12:00:00'],
        ];
    }

    /** Responses served before or after a write in the same second must not match a second later. */
    #[DataProvider('servedSeconds')]
    public function test_write_in_the_served_second_advances_the_validator(string $now_gmt, string $core_gmt): void
    {
        $now = strtotime($now_gmt . ' GMT');
        $at = function (int $time) {
            $_SERVER['REQUEST_TIME'] = $time;
            Functions\when('TekstTV\\time')->justReturn($time);
            Functions\when('current_datetime')->justReturn((new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('Europe/Amsterdam')));
        };
        $at($now);
        Functions\when('is_feed')->justReturn(true);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->revision = $value;
            return true;
        });
        Functions\when('get_lastpostmodified')->justReturn($core_gmt);

        $before = RssFeed::last_modified($core_gmt, 'GMT');
        RssFeed::invalidate();
        RssFeed::save_revision();
        $after = RssFeed::last_modified($core_gmt, 'GMT');
        $this->assertLessThanOrEqual($now_gmt, $after);
        $at($now + 1);
        $this->assertGreaterThan(max($before, $after), RssFeed::last_modified($core_gmt, 'GMT'));
    }

    /** A write that lands after this request read its data must not share its validator. */
    public function test_validator_never_passes_the_request_start(): void
    {
        Functions\when('is_feed')->justReturn(true);
        $this->revision = strtotime('2026-10-19 10:00:01 GMT');
        Functions\when('TekstTV\\time')->justReturn($this->revision);
        Functions\when('current_datetime')->justReturn((new \DateTimeImmutable('@' . $this->revision))->setTimezone(new \DateTimeZone('Europe/Amsterdam')));
        $this->assertSame('2026-10-19 10:00:00', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
    }

    public function test_feed_headers_require_revalidation_only_for_the_export(): void
    {
        Functions\when('is_feed')->justReturn(true);
        $this->assertSame('no-cache', RssFeed::feed_headers([])['Cache-Control']);
        $this->assertSame('no-store', RssFeed::feed_headers(['Cache-Control' => 'no-store'])['Cache-Control']);
        $this->features = Helpers::DEFAULT_FEATURES;
        $this->assertSame([], RssFeed::feed_headers([]));
    }

    public function test_future_revisions_are_served_as_the_current_time(): void
    {
        Functions\when('is_feed')->justReturn(true);
        $this->revision = strtotime('2026-10-19 10:30:00 GMT');
        $this->assertSame('2026-10-19 10:00:00', RssFeed::last_modified('2026-10-01 12:00:00', 'GMT'));
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

    public function test_force_deletes_and_feed_options_are_hooked_but_unrelated_options_are_not(): void
    {
        RssFeed::init();
        $this->assertNotFalse(has_action('before_delete_post'));
        $this->assertNotFalse(has_action('updated_post_meta', [RssFeed::class, 'post_meta_changed']));
        $this->assertNotFalse(has_action('edited_category', [RssFeed::class, 'invalidate']));
        $this->assertNotFalse(has_filter('pre_get_lastpostmodified'));
        $this->assertNotFalse(has_action('update_option_posts_per_rss', [RssFeed::class, 'invalidate']));
        $this->assertSame(10, has_filter('get_lastpostmodified', [RssFeed::class, 'last_modified']));
        $this->assertNotFalse(has_action('transition_post_status', [RssFeed::class, 'post_transitioned']));
        foreach (['added', 'updated', 'deleted'] as $action) {
            $this->assertNotFalse(has_action($action . '_post_meta', [RssFeed::class, 'post_meta_changed']), $action);
            $this->assertNotFalse(has_action($action . '_term_meta', [RssFeed::class, 'meta_changed']), $action);
        }
        foreach (['teksttv_features', 'teksttv_max_post_age', 'timezone_string', 'gmt_offset', 'posts_per_rss'] as $option) {
            foreach (['add_option_', 'update_option_', 'delete_option_'] as $prefix) {
                $this->assertNotFalse(has_action($prefix . $option, [RssFeed::class, 'invalidate']), $prefix . $option);
            }
        }
        $this->assertFalse(has_action('update_option_blogname', [RssFeed::class, 'invalidate']));
    }

    /** @return array<string, array{string, list<mixed>, bool}> */
    public static function closureHooks(): array
    {
        $published = self::makePost();
        $published->post_type = 'post';
        $published->post_status = 'publish';
        $draft = clone $published;
        $draft->post_status = 'draft';
        return [
            'force delete of a published post' => ['before_delete_post', [42, $published], true],
            'force delete of a draft' => ['before_delete_post', [42, $draft], false],
            'category assignment' => ['set_object_terms', [42, [], [], 'category'], true],
            'tag removal' => ['deleted_term_relationships', [42, [], 'post_tag'], true],
            'other taxonomy' => ['set_object_terms', [42, [], [], 'region'], false],
        ];
    }

    /** @param list<mixed> $args */
    #[DataProvider('closureHooks')]
    public function test_closure_hooks_invalidate_feed_changes(string $hook, array $args, bool $invalidates): void
    {
        $callback = null;
        Actions\expectAdded($hook)->whenHappen(function ($added) use (&$callback) {
            $callback = $added;
        });
        RssFeed::init();
        Functions\when('get_post_type')->justReturn('post');
        Functions\when('get_post_status')->justReturn('publish');
        $callback(...$args);
        $this->assertSame($invalidates, has_action('shutdown', [RssFeed::class, 'save_revision']) !== false);
    }

    public function test_values_from_other_plugins_get_the_feed_validator(): void
    {
        $callback = null;
        Filters\expectAdded('pre_get_lastpostmodified')->whenHappen(function ($added) use (&$callback) {
            $callback = $added;
        });
        RssFeed::init();
        Functions\when('is_feed')->justReturn(true);
        $this->assertFalse($callback(false, 'GMT'));
        $this->assertSame('2026-10-18 22:00:00', $callback('2026-10-01 12:00:00', 'GMT'));
    }

    /** @return array<string, array{int, string|false, string|false, bool}> */
    public static function postMetaTargets(): array
    {
        return [
            'published post' => [42, 'post', 'publish', true],
            'draft' => [42, 'post', 'draft', false],
            'published page' => [42, 'page', 'publish', false],
            'attachment' => [42, 'attachment', 'inherit', true],
            'delete by key' => [0, false, false, true],
        ];
    }

    #[DataProvider('postMetaTargets')]
    public function test_post_meta_invalidates_only_for_posts_that_reach_the_feed(int $object_id, string|false $type, string|false $status, bool $invalidates): void
    {
        Functions\when('get_post_type')->justReturn($type);
        Functions\when('get_post_status')->justReturn($status);
        RssFeed::post_meta_changed(['7'], $object_id, '_teksttv_title');
        $this->assertSame($invalidates, has_action('shutdown', [RssFeed::class, 'save_revision']) !== false);
    }

    public function test_untracked_post_meta_skips_the_post_lookup(): void
    {
        Functions\expect('get_post_type')->never();
        RssFeed::post_meta_changed(['7'], 42, '_edit_lock');
        $this->assertFalse(has_action('shutdown', [RssFeed::class, 'save_revision']));
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
