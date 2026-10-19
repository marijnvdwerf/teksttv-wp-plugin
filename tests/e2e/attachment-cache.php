<?php

use TekstTV\ArticleContent;
use TekstTV\Blocks\BuildContext;
use TekstTV\Blocks\Loop\ArticlesLoopBlock;
use TekstTV\Helpers;
use TekstTV\RssFeed;

defined('ABSPATH') || exit;

update_option('teksttv_features', [...Helpers::DEFAULT_FEATURES, 'rss_feed']);
$results = [];
$created_posts = [];
$created_terms = ['category' => [], 'post_tag' => []];
$original_age = get_option('teksttv_max_post_age', 30);
update_option('teksttv_max_post_age', 0);
try {
    foreach ([5, 20] as $count) {
        $group = wp_insert_term('Attachment benchmark ' . $count, 'category')['term_id'];
        $tag = wp_insert_term('Attachment benchmark ' . $count, 'post_tag')['term_id'];
        $post_ids = [];
        $image_ids = [];
        $term_ids = [$group, $tag];
        $created_terms['category'][] = $group;
        $created_terms['post_tag'][] = $tag;
        for ($i = 0; $i < $count; $i++) {
            $images = [];
            for ($j = 0; $j < 3; $j++) {
                $id = wp_insert_attachment(['post_title' => 'Image ' . $count . ' ' . $i . ' ' . $j, 'post_mime_type' => 'image/png']);
                update_attached_file($id, '2026/10/benchmark-' . $id . '.png');
                wp_update_attachment_metadata($id, ['width' => 100, 'height' => 100, 'file' => '2026/10/benchmark-' . $id . '.png', 'sizes' => []]);
                $images[] = $id;
                $image_ids[] = $id;
                $created_posts[] = $id;
            }
            $id = wp_insert_post([
                'post_title' => 'Benchmark ' . $count . ' ' . $i, 'post_status' => 'publish',
                'post_category' => [$group], 'tags_input' => $i % 2 === 0 ? [$tag] : [],
                'meta_input' => [
                    '_teksttv_active' => '1', '_teksttv_content' => 'Benchmark body',
                    '_teksttv_images' => [$images[1], $images[2], 999999 + $i],
                ],
            ]);
            $post_ids[] = $id;
            $created_posts[] = $id;
            if ($i % 4 === 0) {
                update_post_meta($id, '_teksttv_sidebar_image', $images[0]);
            } elseif ($i % 4 === 3) {
                set_post_thumbnail($id, $images[0]);
            } else {
                $term = wp_insert_term('Image category ' . $count . ' ' . $i, 'category')['term_id'];
                update_term_meta($term, '_teksttv_category_image', $images[0]);
                $term_ids[] = $term;
                $created_terms['category'][] = $term;
                // A primary category the post lacks is not in core's lazy term meta queue.
                if ($i % 4 === 1) {
                    update_post_meta($id, '_yoast_wpseo_primary_category', $term);
                } else {
                    wp_set_post_categories($id, [$group, $term]);
                }
            }
        }
        foreach (['rss', 'playout'] as $caller) {
            // A discarded warm-up pass keeps one-time costs out of the first measurement.
            foreach ($caller === 'rss' ? ['warm-up', 'cold', 'warm', 'unprimed'] : ['warm-up', 'cold', 'warm'] as $pass) {
                $cold = $pass !== 'warm';
                ArticleContent::prime_images([]);
                // The query queues its category and tag term meta for lazy loading.
                $query = new WP_Query(['post__in' => $post_ids, 'posts_per_page' => 50, 'no_found_rows' => true, 'ignore_sticky_posts' => true]);
                if ($cold) {
                    wp_cache_delete_multiple($term_ids, 'term_meta');
                    wp_cache_delete_multiple($image_ids, 'posts');
                    wp_cache_delete_multiple($image_ids, 'post_meta');
                }
                $start = $wpdb->num_queries;
                if ($caller === 'rss') {
                    // A main query that ran no query leaves the feed's images unprimed.
                    $GLOBALS['wp_query'] = $pass === 'unprimed' ? new WP_Query() : $query;
                    ob_start();
                    RssFeed::render_namespace();
                    foreach ($query->posts as $post) {
                        $GLOBALS['post'] = $post;
                        RssFeed::render_item();
                    }
                    $output = ob_get_clean();
                } else {
                    BuildContext::reset();
                    $output = wp_json_encode(ArticlesLoopBlock::build(['count' => 50, 'taxonomy_filters' => ['category' => [$group]]]));
                }
                if ($pass === 'warm-up') {
                    continue;
                }
                $slides = $caller === 'playout' ? json_decode($output, true) : [];
                $results[] = [
                    'posts' => $count, 'caller' => $caller, 'cold' => $cold, 'primed' => $pass !== 'unprimed',
                    'queries' => $wpdb->num_queries - $start, 'hash' => hash('sha256', $output),
                    'text_count' => $caller === 'rss' ? substr_count($output, '<teksttv:content>') : count(array_filter($slides, fn($slide) => $slide['type'] === 'text')),
                    'image_count' => $caller === 'rss' ? substr_count($output, '<teksttv:extraImage>') : count(array_filter($slides, fn($slide) => $slide['type'] === 'image')),
                    'sidebar_count' => $caller === 'rss' ? substr_count($output, '<teksttv:image>') : count(array_filter($slides, fn($slide) => isset($slide['image']))),
                ];
            }
        }
    }
    echo wp_json_encode($results);
} finally {
    update_option('teksttv_max_post_age', $original_age);
    foreach ($created_posts as $id) {
        wp_delete_post($id, true);
    }
    foreach ($created_terms as $taxonomy => $ids) {
        foreach ($ids as $id) {
            wp_delete_term($id, $taxonomy);
        }
    }
    BuildContext::reset();
}
