<?php

namespace TekstTV\Blocks\Common;

use TekstTV\Blocks\BuildContext;
use TekstTV\Helpers;

/**
 * Builds recent-post queries with shared age, taxonomy, and deduplication rules.
 */
final class RecentPostsQuery
{
    /**
     * @param array<string, mixed> $taxonomy_filters Keyed by taxonomy name, values are term ID arrays.
     * @param array<string, mixed> $extra Merged over the base args (e.g. meta_query).
     * @return array<string, mixed>
     */
    public static function args(int $count, array $taxonomy_filters, array $extra = []): array
    {
        $args = [
            'post_type' => 'post',
            'posts_per_page' => $count,
            'post_status' => 'publish',
            'no_found_rows' => true,
        ];

        $exclude = BuildContext::get_seen_post_ids();
        if (!empty($exclude)) {
            $args['post__not_in'] = $exclude;
        }

        $max_age = self::max_age_days();
        if ($max_age > 0) {
            $args['date_query'] = [
                ['after' => $max_age . ' days ago'],
            ];
        }

        $tax_query = Helpers::build_tax_query($taxonomy_filters);
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        return array_merge($args, $extra);
    }

    /** Maximum post age in days; 0 disables the limit. */
    public static function max_age_days(): int
    {
        return (int) get_option('teksttv_max_post_age', 30);
    }

    /**
     * Calculates expiry in local calendar days; null disables the limit, 0 rejects invalid dates.
     */
    public static function expires_at(\WP_Post $post): ?int
    {
        $max_age = self::max_age_days();
        if ($max_age <= 0) {
            return null;
        }
        $published = get_post_datetime($post);
        return $published ? $published->modify('+' . $max_age . ' days')->getTimestamp() : 0;
    }
}
