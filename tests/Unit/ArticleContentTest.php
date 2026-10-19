<?php

namespace TekstTV\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TekstTV\ArticleContent;
use TekstTV\Helpers;

class ArticleContentTest extends TestCase
{
    /** @var list<string> */
    private array $features = Helpers::DEFAULT_FEATURES;

    /** @var array<string, mixed> */
    private array $meta = ['_teksttv_active' => '1', '_teksttv_content' => 'Tekst'];

    private int $max_age = 30;

    private \WP_Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->post = self::makePost();
        $this->post->post_date = '2026-10-19 11:00:00';
        Functions\when('get_option')->alias(fn($key, $default = false) => match ($key) {
            'teksttv_features' => $this->features,
            'teksttv_max_post_age' => $this->max_age,
            default => $default,
        });
        Functions\when('get_post_meta')->alias(fn($id, $key) => $this->meta[$key] ?? '');
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('Europe/Amsterdam')));
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Amsterdam'));
        Functions\when('get_post_datetime')->alias(fn($post) => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $post->post_date, new \DateTimeZone('Europe/Amsterdam')));
        Functions\when('get_the_title')->justReturn('Gewone titel');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_thumbnail_id')->justReturn(false);
        Functions\when('apply_filters')->alias(fn($hook, $value) => $value);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => $id === 999 ? false : 'https://example.com/' . $id . '.jpg');
        Functions\when('wp_get_attachment_caption')->justReturn('Bijschrift');
    }

    protected function tearDown(): void
    {
        // Clear the missing-attachment list.
        ArticleContent::prime_images([]);
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_prepares_title_pages_and_image_data_in_saved_order(): void
    {
        $this->meta += ['_teksttv_title' => 'Eigen kop', '_teksttv_sidebar_image' => '10', '_teksttv_images' => [12, 999, new \stdClass(), [11], '00', 0.5, '-5', '12.5', true, 11, 12, '13abc']];
        $this->meta['_teksttv_content'] = "Eerste\n---\nTweede";
        $article = ArticleContent::prepare($this->post);
        $this->assertSame('Eigen kop', $article['title']);
        $this->assertSame(['Eerste', 'Tweede'], $article['pages']);
        $this->assertSame(['url' => 'https://example.com/10.jpg', 'caption' => 'Bijschrift'], $article['sidebar_image']);
        $this->assertSame(['https://example.com/12.jpg', 'https://example.com/11.jpg', 'https://example.com/12.jpg'], array_column($article['images'], 'url'));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function excludedMeta(): iterable
    {
        yield 'inactive' => ['_teksttv_active', '0'];
        yield 'never activated' => ['_teksttv_active', ''];
        yield 'no content' => ['_teksttv_content', " \n\t"];
        yield 'only separators' => ['_teksttv_content', "---\n---"];
        yield 'corrupt content' => ['_teksttv_content', new \stdClass()];
        yield 'corrupt title' => ['_teksttv_title', ['kop']];
        yield 'future start' => ['_teksttv_date_start', '2026-10-20'];
        yield 'expired end' => ['_teksttv_date_end', '2026-10-18'];
        yield 'invalid start' => ['_teksttv_date_start', 'invalid'];
        yield 'invalid end' => ['_teksttv_date_end', 'invalid'];
        yield 'impossible date' => ['_teksttv_date_start', '2026-02-31'];
        yield 'NUL date' => ['_teksttv_date_end', "2026-10-19\0"];
        yield 'embedded NUL date' => ['_teksttv_date_start', "2026-10\0-19"];
        yield 'corrupt date' => ['_teksttv_date_start', new \stdClass()];
        yield 'object end date' => ['_teksttv_date_end', new \stdClass()];
        yield 'wrong weekday' => ['_teksttv_days', ['2', '3']];
        yield 'no weekdays' => ['_teksttv_days', []];
        yield 'partly corrupt weekdays' => ['_teksttv_days', ['1', new \stdClass()]];
        yield 'imported weekday string' => ['_teksttv_days', '1,2,3'];
    }

    #[DataProvider('excludedMeta')]
    public function test_rejects_ineligible_or_malformed_content(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
        $this->assertNull(ArticleContent::prepare($this->post));
    }

    public function test_schedule_boundaries_are_inclusive(): void
    {
        $this->meta += ['_teksttv_date_start' => '2026-10-19', '_teksttv_date_end' => '2026-10-19', '_teksttv_days' => ['1']];
        $this->assertNotNull(ArticleContent::prepare($this->post));
    }

    public function test_disabled_features_ignore_retained_even_corrupt_meta(): void
    {
        $this->features = [];
        $this->meta += ['_teksttv_title' => new \stdClass(), '_teksttv_date_start' => new \stdClass(), '_teksttv_days' => []];
        $this->meta['_teksttv_images'] = [10];
        $this->meta['_teksttv_sidebar_image'] = '10';
        Functions\when('get_post_thumbnail_id')->justReturn(12);
        $article = ArticleContent::prepare($this->post);
        $this->assertSame('Gewone titel', $article['title']);
        $this->assertSame([], $article['images']);
        $this->assertSame('https://example.com/12.jpg', $article['sidebar_image']['url']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function postAges(): iterable
    {
        yield 'at the exclusive cutoff' => ['2026-10-19 12:00:00', '2026-09-19 12:00:00', false];
        yield 'just after the cutoff' => ['2026-10-19 12:00:00', '2026-09-19 12:00:01', true];
        yield 'across the spring DST change' => ['2026-04-01 12:00:00', '2026-03-02 11:30:00', false];
        yield 'across the autumn DST change' => ['2026-11-01 12:00:00', '2026-10-02 12:30:00', true];
        // 02:30 occurs twice on 2026-10-25; the post expires at the first and stays expired.
        yield 'during the repeated autumn hour' => ['2026-10-25T01:00:00+00:00', '2026-09-25 02:30:00', false];
    }

    #[DataProvider('postAges')]
    public function test_age_cutoff_uses_calendar_days_across_dst(string $now, string $post_date, bool $eligible): void
    {
        Functions\when('current_datetime')->justReturn((new \DateTimeImmutable($now, new \DateTimeZone('Europe/Amsterdam')))->setTimezone(new \DateTimeZone('Europe/Amsterdam')));
        $this->post->post_date = $post_date;
        $this->assertSame($eligible, ArticleContent::prepare($this->post) !== null);
    }

    public function test_zero_age_limit_and_password_policy_belong_to_callers(): void
    {
        $this->max_age = 0;
        $this->post->post_date = '2000-01-01 00:00:00';
        $this->post->post_password = 'secret';
        $this->assertNotNull(ArticleContent::prepare($this->post));
    }

    public function test_url_filter_suppresses_images_including_image_only_posts(): void
    {
        $this->meta['_teksttv_images'] = [10, 999];
        $this->meta['_teksttv_sidebar_image'] = '11';
        Functions\when('apply_filters')->alias(fn($hook, $value) => $hook === 'teksttv_image_url' ? '' : $value);
        $article = ArticleContent::prepare($this->post);
        $this->assertSame([], $article['images']);
        $this->assertNull($article['sidebar_image']);
        $this->meta['_teksttv_content'] = '';
        $this->assertNull(ArticleContent::prepare($this->post));
    }

    public function test_image_only_content_preserves_image_metadata_without_sidebar(): void
    {
        $this->meta['_teksttv_content'] = '';
        $this->meta['_teksttv_images'] = [10];
        $this->meta['_teksttv_sidebar_image'] = '11';
        $article = ArticleContent::prepare($this->post);
        $this->assertSame([], $article['pages']);
        $this->assertNull($article['sidebar_image']);
        $this->assertSame('Bijschrift', $article['images'][0]['caption']);
    }

    public function test_primes_sources_in_batches_and_skips_missing_attachments(): void
    {
        $this->meta += ['_teksttv_sidebar_image' => '14', '_teksttv_images' => [12, 999, new \stdClass(), 12]];
        Functions\when('get_the_terms')->justReturn([(object) ['term_id' => 8]]);
        Functions\when('get_post_thumbnail_id')->justReturn(13);
        Functions\when('get_term_meta')->alias(fn($id) => $id === 7 ? '10' : '11');
        Functions\when('apply_filters')->alias(fn($hook, $value) => $hook === 'teksttv_primary_category' ? 7 : $value);
        Functions\expect('wp_lazyload_term_meta')->once()->with([7, 8]);
        Functions\expect('_prime_post_caches')->once()->with([14, 13, 12, 999, 10, 11], false, true);
        Functions\expect('_get_non_cached_ids')->once()->with([14, 13, 12, 999, 10, 11], 'posts')->andReturn([999]);
        Functions\when('has_filter')->justReturn(false);
        $this->wpdb(['999' => false]);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => $id === 999 ? $this->fail('Missing attachment was looked up again.') : 'https://example.com/' . $id . '.jpg');
        ArticleContent::prime_images([$this->post]);
        $article = ArticleContent::prepare($this->post);
        $this->assertSame('https://example.com/14.jpg', $article['sidebar_image']['url']);
        $this->assertSame(['https://example.com/12.jpg', 'https://example.com/12.jpg'], array_column($article['images'], 'url'));
    }

    /**
     * Rows that exist, keyed by ID; asserts the raw lookup covers every type and status.
     *
     * @param array<string, bool> $rows
     */
    private function wpdb(array $rows, int $times = 1): void
    {
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->times($times)->with('SELECT ID FROM wp_posts WHERE ID IN (' . implode(',', array_keys($rows)) . ')')
            ->andReturn(array_map('strval', array_keys(array_filter($rows))));
        $GLOBALS['wpdb'] = $wpdb;
    }

    /** @return array<string, array{0: bool, 1: bool, 2: list<int>, 3?: string}> */
    public static function uncachedAttachments(): array
    {
        return [
            'existing attachment the cache did not keep' => [false, false, [999]],
            'image filter that resolves IDs without a post' => [true, true, []],
            'image filter added after priming' => [false, true, []],
            'image src filter added after priming' => [false, true, [], 'wp_get_attachment_image_src'],
        ];
    }

    /** Priming must not hide images that rendering without priming would show. */
    #[DataProvider('uncachedAttachments')]
    public function test_uncached_attachments_are_only_skipped_when_absent(bool $filter_when_priming, bool $filter_when_rendering, array $existing, string $filter_hook = 'image_downsize'): void
    {
        $this->meta['_teksttv_images'] = [999];
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => 'https://example.com/' . $id . '.jpg');
        Functions\when('_prime_post_caches')->justReturn(null);
        Functions\when('_get_non_cached_ids')->justReturn([999]);
        // A filter present while priming makes the lookup pointless.
        $this->wpdb(['999' => $existing !== []], $filter_when_priming ? 0 : 1);
        $filter = $filter_when_priming;
        Functions\when('has_filter')->alias(function ($hook) use (&$filter, $filter_hook) {
            return $filter && $hook === $filter_hook;
        });
        ArticleContent::prime_images([$this->post]);
        $filter = $filter_when_rendering;
        $this->assertSame('https://example.com/999.jpg', ArticleContent::prepare($this->post)['images'][0]['url']);
    }

    public function test_priming_skips_non_posts_and_inactive_posts(): void
    {
        $this->meta['_teksttv_images'] = [12];
        Functions\expect('_prime_post_caches')->never();
        ArticleContent::prime_images([(object) ['ID' => $this->post->ID]]);
        $this->meta['_teksttv_active'] = '0';
        ArticleContent::prime_images([$this->post]);
    }

    public function test_string_keyed_extra_images_meta_is_primed_and_rendered_in_order(): void
    {
        $this->meta['_teksttv_images'] = ['first' => 12, 'second' => 11];
        Functions\expect('_prime_post_caches')->once()->with([12, 11], false, true);
        Functions\when('_get_non_cached_ids')->justReturn([]);
        ArticleContent::prime_images([$this->post]);
        $article = ArticleContent::prepare($this->post);
        $this->assertSame(['https://example.com/12.jpg', 'https://example.com/11.jpg'], array_column($article['images'], 'url'));
    }

    /** @return iterable<string, array{string, list<string>, 2?: bool}> */
    public static function pageSplits(): iterable
    {
        yield 'single page' => ['<p>Hello world</p>', ['<p>Hello world</p>']];
        yield 'HTML separator' => ['<p>Page one</p><p>---</p><p>Page two</p>', ['<p>Page one</p>', '<p>Page two</p>']];
        yield 'longer separator' => ['<p>One</p><p>-----</p><p>Two</p>', ['<p>One</p>', '<p>Two</p>']];
        yield 'edge separators' => ['<p>---</p><p>Only page</p><p>---</p>', ['<p>Only page</p>']];
        yield 'plain-text separator' => ["Page one\n---\nPage two", ['Page one', 'Page two']];
        yield 'inline hyphens' => ['foo---bar', ['foo---bar']];
        yield 'empty' => ['', []];
        yield 'whitespace only' => ['   ', []];
        yield 'zero is content' => ['0', ['0']];
        yield 'feature disabled' => ['<p>Page one</p><p>---</p><p>Page two</p>', ['<p>Page one</p><p>---</p><p>Page two</p>'], false];
    }

    /** @param list<string> $expected */
    #[DataProvider('pageSplits')]
    public function test_split_pages(string $content, array $expected, bool $separator = true): void
    {
        $this->features = $separator ? ['page_separator'] : [];
        $this->assertSame($expected, ArticleContent::split_pages($content));
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function corruptSidebarIds(): iterable
    {
        yield 'object override' => [new \stdClass(), '', ''];
        yield 'array primary category' => ['', ['10'], ''];
        yield 'array category image' => ['', '', ['88']];
    }

    #[DataProvider('corruptSidebarIds')]
    public function test_sidebar_image_treats_corrupt_ids_as_unavailable(mixed $override, mixed $primary, mixed $category_image): void
    {
        $this->meta['_teksttv_sidebar_image'] = $override;
        Functions\when('get_the_terms')->justReturn([(object) ['term_id' => 10]]);
        // If cast to int, a corrupt ID would become 1, which resolves to an image here.
        Functions\when('get_term_meta')->alias(fn($term_id) => $term_id === 1 ? '5' : $category_image);
        Functions\when('get_post_thumbnail_id')->justReturn(77);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => 'https://example.com/' . $id . '.jpg');
        Functions\when('wp_get_attachment_caption')->justReturn('');
        Functions\when('apply_filters')->alias(fn($tag, $value) => $tag === 'teksttv_primary_category' ? $primary : $value);

        $this->assertSame('https://example.com/77.jpg', ArticleContent::get_sidebar_image_data(1)['url']);
    }

    public function test_sidebar_image_ignores_corrupt_thumbnail_meta(): void
    {
        $this->meta['_thumbnail_id'] = new \stdClass();
        Functions\when('get_post_thumbnail_id')->alias(fn() => $this->fail('Corrupt thumbnail meta was resolved.'));

        $this->assertNull(ArticleContent::get_sidebar_image_data(1));
    }

    /** @return iterable<string, array{mixed, mixed, array<int, string>, int, int|null}> */
    public static function sidebarSources(): iterable
    {
        // Override, filtered primary category, category images by term ID, thumbnail ID, expected image ID.
        yield 'explicit none' => ['0', '', [], 77, null];
        yield 'integer zero' => [0, '', [], 77, null];
        yield 'custom override' => ['42', 10, [10 => '88'], 77, 42];
        yield 'primary category first' => ['', 10, [10 => '88', 20 => '55'], 77, 88];
        yield 'first category with an image' => ['', '', [10 => '', 20 => '55'], 77, 55];
        yield 'featured image' => ['', '', [10 => ''], 77, 77];
        yield 'nothing' => ['', '', [], 0, null];
    }

    /** @param array<int, string> $category_images */
    #[DataProvider('sidebarSources')]
    public function test_sidebar_image_fallback_order(mixed $override, mixed $primary, array $category_images, int $thumbnail, ?int $expected): void
    {
        $this->meta['_teksttv_sidebar_image'] = $override;
        $terms = array_map(fn($id) => (object) ['term_id' => $id], array_keys($category_images));
        Functions\when('get_the_terms')->justReturn($primary === '' ? $terms : array_slice($terms, 1));
        Functions\when('get_term_meta')->alias(fn($term_id) => $category_images[$term_id] ?? '');
        Functions\when('get_post_thumbnail_id')->justReturn($thumbnail);
        Functions\when('apply_filters')->alias(fn($tag, $value) => $tag === 'teksttv_primary_category' ? $primary : $value);

        $result = ArticleContent::get_sidebar_image_data(1);
        $this->assertSame($expected === null ? null : 'https://example.com/' . $expected . '.jpg', $result['url'] ?? null);
    }
}
