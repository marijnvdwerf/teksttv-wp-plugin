<?php

namespace TekstTV\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
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
        Functions\when('get_the_title')->justReturn('Gewone titel');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_thumbnail_id')->justReturn(false);
        Functions\when('apply_filters')->alias(fn($hook, $value) => $value);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => $id === 999 ? false : 'https://example.com/' . $id . '.jpg');
        Functions\when('wp_get_attachment_caption')->justReturn('Bijschrift');
    }

    public function test_prepares_title_pages_and_image_data_in_saved_order(): void
    {
        $this->meta += ['_teksttv_title' => 'Eigen kop', '_teksttv_sidebar_image' => '10', '_teksttv_images' => [12, 999, new \stdClass(), 11, 12]];
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
        yield 'corrupt date' => ['_teksttv_date_start', new \stdClass()];
        yield 'wrong weekday' => ['_teksttv_days', ['2']];
        yield 'no weekdays' => ['_teksttv_days', []];
        yield 'corrupt weekday' => ['_teksttv_days', [new \stdClass()]];
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
        $article = ArticleContent::prepare($this->post);
        $this->assertSame('Gewone titel', $article['title']);
        $this->assertSame([], $article['images']);
        $this->assertNull($article['sidebar_image']);
    }

    #[DataProviderExternal(RssFeedTest::class, 'postAges')]
    public function test_age_cutoff_uses_calendar_days_across_dst(string $now, string $post_date, bool $eligible): void
    {
        Functions\when('current_datetime')->justReturn(new \DateTimeImmutable($now, new \DateTimeZone('Europe/Amsterdam')));
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
        $this->meta['_teksttv_images'] = [10];
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
        Functions\expect('get_posts')->once()->andReturn([]);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => $id === 999 ? $this->fail('Missing attachment was looked up again.') : 'https://example.com/' . $id . '.jpg');
        try {
            ArticleContent::prime_images([$this->post]);
            $article = ArticleContent::prepare($this->post);
            $this->assertSame('https://example.com/14.jpg', $article['sidebar_image']['url']);
            $this->assertSame(['https://example.com/12.jpg', 'https://example.com/12.jpg'], array_column($article['images'], 'url'));
        } finally {
            ArticleContent::prime_images([]);
        }
    }

    /** @return array<string, array{bool, list<int>}> */
    public static function uncachedAttachments(): array
    {
        return [
            'existing attachment the cache did not keep' => [false, [999]],
            'image filter that resolves IDs without a post' => [true, []],
        ];
    }

    /** Priming must not hide images that rendering without priming would show. */
    #[DataProvider('uncachedAttachments')]
    public function test_uncached_attachments_are_only_skipped_when_absent(bool $image_filter, array $existing): void
    {
        $this->meta['_teksttv_images'] = [999];
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => 'https://example.com/' . $id . '.jpg');
        Functions\when('_prime_post_caches')->justReturn(null);
        Functions\when('_get_non_cached_ids')->justReturn([999]);
        Functions\when('has_filter')->alias(fn($hook) => $image_filter && $hook === 'image_downsize');
        Functions\when('get_posts')->justReturn($existing);
        try {
            ArticleContent::prime_images([$this->post]);
            $this->assertSame('https://example.com/999.jpg', ArticleContent::prepare($this->post)['images'][0]['url']);
        } finally {
            ArticleContent::prime_images([]);
        }
    }

    public function test_priming_skips_non_posts_and_inactive_posts(): void
    {
        $this->meta['_teksttv_images'] = [12];
        Functions\expect('_prime_post_caches')->never();
        Functions\when('_get_non_cached_ids')->justReturn([]);
        ArticleContent::prime_images([(object) ['ID' => $this->post->ID]]);
        $this->meta['_teksttv_active'] = '0';
        ArticleContent::prime_images([$this->post]);
    }

    public function test_string_keyed_extra_images_meta_is_primed_and_rendered_in_order(): void
    {
        $this->meta['_teksttv_images'] = ['first' => 12, 'second' => 11];
        Functions\expect('_prime_post_caches')->once()->with([12, 11], false, true);
        Functions\when('_get_non_cached_ids')->justReturn([]);
        try {
            ArticleContent::prime_images([$this->post]);
            $article = ArticleContent::prepare($this->post);
            $this->assertSame(['https://example.com/12.jpg', 'https://example.com/11.jpg'], array_column($article['images'], 'url'));
        } finally {
            ArticleContent::prime_images([]);
        }
    }
}
