<?php

namespace TekstTV\Tests\Unit\Blocks\Loop;

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
}
