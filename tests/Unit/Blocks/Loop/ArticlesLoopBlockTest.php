<?php

namespace TekstTV\Tests\Unit\Blocks\Loop;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TekstTV\Blocks\Loop\ArticlesLoopBlock;
use TekstTV\Tests\Unit\TestCase;

class ArticlesLoopBlockTest extends TestCase
{
    public function test_save_defaults(): void
    {
        $result = ArticlesLoopBlock::save([]);
        $this->assertSame(3, $result['count']);
        $this->assertSame([], $result['taxonomy_filters']);
    }

    public function test_save_with_count(): void
    {
        $result = ArticlesLoopBlock::save(['count' => '10']);
        $this->assertSame(10, $result['count']);
    }

    public function test_save_clamps_count_to_ui_max(): void
    {
        $result = ArticlesLoopBlock::save(['count' => '9999']);
        $this->assertSame(50, $result['count']);
    }

    public function test_save_clamps_durations_to_ui_max(): void
    {
        $result = ArticlesLoopBlock::save([
            'duration_text' => '9999',
            'duration_image' => '9999',
        ]);
        $this->assertSame(120, $result['duration_text']);
        $this->assertSame(120, $result['duration_image']);
    }

    public function test_save_with_durations(): void
    {
        $result = ArticlesLoopBlock::save([
            'duration_text' => '15',
            'duration_image' => '5',
        ]);
        $this->assertSame(15, $result['duration_text']);
        $this->assertSame(5, $result['duration_image']);
    }

    public function test_save_omits_empty_durations(): void
    {
        $result = ArticlesLoopBlock::save([
            'duration_text' => '',
            'duration_image' => '',
        ]);
        $this->assertArrayNotHasKey('duration_text', $result);
        $this->assertArrayNotHasKey('duration_image', $result);
    }

    public function test_save_with_taxonomy_filters(): void
    {
        $result = ArticlesLoopBlock::save([
            'count' => '5',
            'taxonomy_filters' => ['category' => ['1', '3']],
        ]);

        $this->assertSame(5, $result['count']);
        $this->assertArrayHasKey('category', $result['taxonomy_filters']);
        $this->assertSame([1, 3], $result['taxonomy_filters']['category']);
    }

    public function test_split_pages_single_page(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $result = ArticlesLoopBlock::split_pages('<p>Hello world</p>');
        $this->assertSame(['<p>Hello world</p>'], $result);
    }

    public function test_split_pages_with_html_separator(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $result = ArticlesLoopBlock::split_pages('<p>Page one</p><p>---</p><p>Page two</p>');
        $this->assertCount(2, $result);
        $this->assertSame('<p>Page one</p>', $result[0]);
        $this->assertSame('<p>Page two</p>', $result[1]);
    }

    public function test_split_pages_with_multiple_dashes(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $result = ArticlesLoopBlock::split_pages('<p>One</p><p>-----</p><p>Two</p>');
        $this->assertCount(2, $result);
    }

    public function test_split_pages_filters_empty_parts(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $result = ArticlesLoopBlock::split_pages('<p>---</p><p>Only page</p><p>---</p>');
        $this->assertCount(1, $result);
        $this->assertSame('<p>Only page</p>', $result[0]);
    }

    public function test_split_pages_without_feature_returns_single_page(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn([]);

        $content = '<p>Page one</p><p>---</p><p>Page two</p>';
        $result = ArticlesLoopBlock::split_pages($content);
        $this->assertCount(1, $result);
        $this->assertSame($content, $result[0]);
    }

    public function test_split_pages_empty_content(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $this->assertSame([], ArticlesLoopBlock::split_pages(''));
    }

    public function test_split_pages_whitespace_only(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $this->assertSame([], ArticlesLoopBlock::split_pages('   '));
    }

    public function test_split_pages_with_plain_text_separator(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $result = ArticlesLoopBlock::split_pages("Page one\n---\nPage two");
        $this->assertCount(2, $result);
        $this->assertSame('Page one', $result[0]);
        $this->assertSame('Page two', $result[1]);
    }

    public function test_split_pages_preserves_inline_hyphens(): void
    {
        Functions\expect('get_option')
            ->with('teksttv_features', \Mockery::any())
            ->andReturn(['page_separator']);

        $this->assertSame(['foo---bar'], ArticlesLoopBlock::split_pages('foo---bar'));
    }

    public function test_sidebar_image_override_with_explicit_none(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn('0');

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertNull($result);
    }

    public function test_sidebar_image_override_with_integer_zero(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn(0);

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertNull($result);
    }

    public function test_sidebar_image_custom_override(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn('42');
        Functions\expect('wp_get_attachment_image_url')->with(42, 'large')->andReturn('https://example.com/custom.jpg');
        Functions\expect('wp_get_attachment_caption')->with(42)->andReturn('');
        Functions\expect('apply_filters')->andReturnUsing(function ($tag, $value) {
            return $tag === 'teksttv_image_url' ? $value : '';
        });

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertSame('https://example.com/custom.jpg', $result['url']);
    }

    public function test_sidebar_image_falls_back_to_category_image(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn('');

        Functions\expect('get_the_terms')->with(1, 'category')->andReturn([(object) ['term_id' => 10], (object) ['term_id' => 20]]);
        Functions\expect('get_term_meta')
            ->with(10, '_teksttv_category_image', true)
            ->andReturn('55');
        Functions\expect('wp_get_attachment_image_url')->with(55, 'large')->andReturn('https://example.com/cat.jpg');
        Functions\expect('wp_get_attachment_caption')->with(55)->andReturn('');
        Functions\expect('apply_filters')->andReturnUsing(function ($tag, $value) {
            return $tag === 'teksttv_image_url' ? $value : '';
        });

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertSame('https://example.com/cat.jpg', $result['url']);
    }

    public function test_sidebar_image_falls_back_to_post_thumbnail(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn('');

        Functions\expect('get_the_terms')->with(1, 'category')->andReturn([(object) ['term_id' => 10]]);
        Functions\expect('get_term_meta')
            ->with(10, '_teksttv_category_image', true)
            ->andReturn('');

        Functions\expect('get_post_thumbnail_id')->with(1)->andReturn(77);
        Functions\expect('wp_get_attachment_image_url')->with(77, 'large')->andReturn('https://example.com/thumb.jpg');
        Functions\expect('wp_get_attachment_caption')->with(77)->andReturn('');
        Functions\expect('apply_filters')->andReturnUsing(function ($tag, $value) {
            return $tag === 'teksttv_image_url' ? $value : '';
        });

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertSame('https://example.com/thumb.jpg', $result['url']);
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
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === '_teksttv_sidebar_image' ? $override : '');
        Functions\when('get_the_terms')->justReturn([(object) ['term_id' => 10]]);
        // A corrupt ID cast to int becomes 1, which resolves to an image here.
        Functions\when('get_term_meta')->alias(fn($term_id) => $term_id === 1 ? '5' : $category_image);
        Functions\when('get_post_thumbnail_id')->justReturn(77);
        Functions\when('wp_get_attachment_image_url')->alias(fn($id) => 'https://example.com/' . $id . '.jpg');
        Functions\when('wp_get_attachment_caption')->justReturn('');
        Functions\when('apply_filters')->alias(fn($tag, $value) => $tag === 'teksttv_primary_category' ? $primary : $value);

        $this->assertSame('https://example.com/77.jpg', ArticlesLoopBlock::get_sidebar_image_data(1)['url']);
    }

    public function test_sidebar_image_ignores_corrupt_thumbnail_meta(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === '_thumbnail_id' ? new \stdClass() : '');
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('apply_filters')->returnArg(2);
        Functions\expect('get_post_thumbnail_id')->never();

        $this->assertNull(ArticlesLoopBlock::get_sidebar_image_data(1));
    }

    public function test_sidebar_image_returns_null_when_nothing_found(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn('');

        Functions\expect('apply_filters')
            ->with('teksttv_primary_category', \Mockery::any(), 1)
            ->andReturn('');

        Functions\expect('get_the_terms')->with(1, 'category')->andReturn(false);
        Functions\expect('get_post_thumbnail_id')->with(1)->andReturn(0);

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertNull($result);
    }

    public function test_sidebar_image_primary_category_takes_precedence(): void
    {
        Functions\when('get_option')->justReturn(['sidebar_image']);
        Functions\expect('get_post_meta')
            ->with(1, '_teksttv_sidebar_image', true)
            ->andReturn('');

        Functions\expect('get_term_meta')
            ->with(10, '_teksttv_category_image', true)
            ->andReturn('88');
        Functions\expect('wp_get_attachment_image_url')->with(88, 'large')->andReturn('https://example.com/primary.jpg');
        Functions\expect('wp_get_attachment_caption')->with(88)->andReturn('');
        Functions\expect('apply_filters')->andReturnUsing(function ($tag, $value) {
            return match ($tag) {
                'teksttv_primary_category' => 10,
                'teksttv_image_url' => $value,
                default => '',
            };
        });

        $result = ArticlesLoopBlock::get_sidebar_image_data(1);
        $this->assertSame('https://example.com/primary.jpg', $result['url']);
    }

}
