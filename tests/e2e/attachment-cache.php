<?php

use TekstTV\ArticleContent;
use TekstTV\Blocks\BuildContext;
use TekstTV\Blocks\Loop\ArticlesLoopBlock;
use TekstTV\Helpers;
use TekstTV\RssFeed;

update_option('teksttv_features', [...Helpers::DEFAULT_FEATURES, 'rss_feed']);
$results = [];
$created_posts = [];
$created_terms = [];
$original_age = get_option('teksttv_max_post_age', 30);
update_option('teksttv_max_post_age', 0);
try {
    foreach ([5, 20] as $count) {
        $group = wp_insert_term('Attachment benchmark ' . $count, 'category')['term_id'];
        $post_ids = [];
        $image_ids = [];
        $term_ids = [$group];
        $created_terms[] = $group;
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
                'post_category' => [$group],
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
                wp_set_post_categories($id, [$group, $term]);
                update_term_meta($term, '_teksttv_category_image', $images[0]);
                $term_ids[] = $term;
                $created_terms[] = $term;
                if ($i % 4 === 1) {
                    update_post_meta($id, '_yoast_wpseo_primary_category', $term);
                }
            }
        }
        foreach (['rss', 'playout'] as $caller) {
            foreach ([true, false] as $cold) {
                ArticleContent::prime_images([]);
                $query = new WP_Query(['post__in' => $post_ids, 'posts_per_page' => 50, 'no_found_rows' => true]);
                update_object_term_cache($post_ids, 'post');
                // Resolve lazy term loads before measuring, like a populated main query.
                foreach ($term_ids as $term_id) {
                    get_term_meta($term_id, '_teksttv_category_image', true);
                    if ($cold) {
                        wp_cache_delete($term_id, 'term_meta');
                    }
                }
                foreach ($cold ? $image_ids : [] as $image_id) {
                    wp_cache_delete($image_id, 'posts');
                    wp_cache_delete($image_id, 'post_meta');
                }
                $start = $wpdb->num_queries;
                if ($caller === 'rss') {
                    $GLOBALS['wp_query'] = $query;
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
                $slides = $caller === 'playout' ? json_decode($output, true) : [];
                $results[] = [
                    'posts' => $count, 'caller' => $caller, 'cold' => $cold,
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
    foreach ($created_terms as $id) {
        wp_delete_term($id, 'category');
    }
    BuildContext::reset();
}
