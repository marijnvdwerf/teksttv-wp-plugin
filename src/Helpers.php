<?php

namespace TekstTV;

use DateTime;
use DateTimeInterface;

/**
 * @phpstan-type AiConfig array{system: string, prompt_title: string, prompt_body: string, word_limit: int, word_limit_photo: int, title_char_limit: int, min_input_words: int, ensure_terminal_period: bool, region_taxonomy: string, provider: string, model: string}
 */
class Helpers
{
    /** @var list<array{name: string, label: string, terms: array<int, string>}>|null */
    private static ?array $post_taxonomies_cache = null;

    /** Cache the expensive model-discovery result per request. */
    private static ?bool $ai_supported_cache = null;

    /** Build an ID that matches the workbench.ts reindexer. */
    public static function field_id(string $prefix, int|string $index, string $field): string
    {
        return str_replace('_', '-', $prefix . '-' . (string) $index . '-' . $field);
    }

    /** Echo the escaped `for` attribute matching field_attrs(). */
    public static function field_for(string $prefix, int|string $index, string $field): void
    {
        echo 'for="' . esc_attr(self::field_id($prefix, $index, $field)) . '"';
    }

    /** Echo escaped `id` and `name` attributes for a repeated field. */
    public static function field_attrs(string $prefix, int|string $index, string $field): void
    {
        echo 'id="' . esc_attr(self::field_id($prefix, $index, $field)) . '"'
            . ' name="' . esc_attr($prefix . '[' . (string) $index . '][' . $field . ']') . '"';
    }

    /**
     * Translated ISO-8601 weekday labels (1=Mon..7=Sun).
     *
     * PHP normalizes the keys to integers.
     *
     * @return array<int, string>
     */
    public static function get_day_labels(): array
    {
        return [
            '1' => 'Ma',
            '2' => 'Di',
            '3' => 'Wo',
            '4' => 'Do',
            '5' => 'Vr',
            '6' => 'Za',
            '7' => 'Zo',
        ];
    }

    /**
     * Sanitize weekday input to ISO-8601 day strings.
     *
     * Null means unrestricted; an empty list means no days.
     *
     * @param mixed $raw
     * @return list<string>|null
     */
    public static function sanitize_days_input(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $valid = ['1', '2', '3', '4', '5', '6', '7'];
        $days = array_values(array_unique(array_intersect(array_map('sanitize_text_field', $raw), $valid)));
        return count($days) < 7 ? $days : null;
    }

    /**
     * Extract scheduling fields from a raw request payload.
     * Empty dates are omitted; an empty days list is preserved.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function extract_scheduling_fields(array $raw): array
    {
        $fields = [];

        $ds = self::sanitize_date_input($raw['date_start'] ?? '');
        $de = self::sanitize_date_input($raw['date_end'] ?? '');
        if ($ds !== '') {
            $fields['date_start'] = $ds;
        }
        if ($de !== '') {
            $fields['date_end'] = $de;
        }

        // A missing checkbox group means no days; null remains unrestricted.
        $raw_days = array_key_exists('days', $raw) ? $raw['days'] : [];
        $sanitized_days = self::sanitize_days_input($raw_days);
        if ($sanitized_days !== null) {
            $fields['days'] = $sanitized_days;
        }

        return $fields;
    }

    /**
     * Normalize days: null means all, an empty list means none.
     *
     * @return list<string>|null
     */
    public static function normalize_days(mixed $days): ?array
    {
        return is_array($days) ? $days : null;
    }

    /**
     * @param list<string>|null $allowed_days ISO-8601 day numbers (1=Mon, 7=Sun); null means all days, [] means none.
     * @param DateTimeInterface|null $date Date to check, defaults to current date
     */
    public static function is_allowed_on_day(?array $allowed_days, ?DateTimeInterface $date = null): bool
    {
        if ($allowed_days === null) {
            return true;
        }
        if ($allowed_days === []) {
            return false;
        }

        $date = $date ?? current_datetime();
        $current_day = $date->format('N');

        return in_array((string) $current_day, array_map('strval', $allowed_days), true);
    }

    /** Return a valid Y-m-d date or an empty string. */
    public static function sanitize_date_input(mixed $raw): string
    {
        $date = sanitize_text_field((string) $raw);
        // DateTime rejects NUL bytes with a ValueError.
        if ($date === '' || str_contains($date, "\0")) {
            return '';
        }

        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : '';
    }

    /**
     * Check whether today falls within an optional date range.
     *
     * @param string|null $start_date Y-m-d format
     * @param string|null $end_date Y-m-d format
     */
    public static function is_within_date_range(?string $start_date, ?string $end_date): bool
    {
        if (empty($start_date) && empty($end_date)) {
            return true;
        }

        $now = current_datetime();
        $timezone = wp_timezone();

        if (!empty($start_date)) {
            $start = DateTime::createFromFormat('Y-m-d', trim($start_date), $timezone);
            if ($start && $now < $start->setTime(0, 0, 0)) {
                return false;
            }
        }

        if (!empty($end_date)) {
            $end = DateTime::createFromFormat('Y-m-d', trim($end_date), $timezone);
            if ($end && $now > $end->setTime(23, 59, 59)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{slug: string, label: string}> */
    public static function get_channels(): array
    {
        $channels = get_option('teksttv_channels', []);
        if (!is_array($channels) || empty($channels)) {
            return [['slug' => 'tv1', 'label' => 'TV 1']];
        }
        return $channels;
    }

    /**
     * @return list<string>
     */
    public static function channel_slugs(): array
    {
        return array_column(self::get_channels(), 'slug');
    }

    /**
     * Default features for option registration and runtime reads.
     */
    public const DEFAULT_FEATURES = [
        'custom_title', 'sidebar_image', 'extra_images',
        'scheduling', 'page_separator',
        'bold', 'italic', 'underline', 'lists',
        'ai_generate',
    ];

    /**
     * @return list<string> Feature slugs.
     */
    public static function get_features(): array
    {
        $features = get_option('teksttv_features', self::DEFAULT_FEATURES);
        return is_array($features) ? $features : self::DEFAULT_FEATURES;
    }

    /**
     * Whether AI is enabled and the configured model is available.
     */
    public static function ai_supported(): bool
    {
        if (!self::has_feature('ai_generate')) {
            return false;
        }

        return self::$ai_supported_cache ??= AiGenerator::supports_text_generation(self::get_ai_prompts());
    }

    public static function has_feature(string $feature): bool
    {
        return in_array($feature, self::get_features(), true);
    }

    /** @param mixed $value */
    public static function clamp_int(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, absint($value)));
    }

    public const DURATION_MIN_SECONDS = 1;
    public const DURATION_MAX_SECONDS = 120;

    /**
     * Duration defaults shared by settings, placeholders, and slide builds.
     */
    public const DURATION_DEFAULTS = [
        'teksttv_duration_text' => 20,
        'teksttv_duration_image' => 7,
        'teksttv_duration_iframe' => 30,
    ];

    /**
     * Resolve a millisecond duration from a block override or type option.
     *
     * @param mixed $override_seconds Stored block value; empty means "use the option".
     * @param key-of<self::DURATION_DEFAULTS> $option_name Duration option to read.
     */
    public static function duration_ms(mixed $override_seconds, string $option_name): int
    {
        $default = self::DURATION_DEFAULTS[$option_name];
        return self::fixed_duration_ms(
            $override_seconds,
            !empty($override_seconds) ? $default : (int) get_option($option_name, $default)
        );
    }

    /**
     * Resolve a millisecond duration from an override or fixed default.
     */
    public static function fixed_duration_ms(mixed $override_seconds, int $default_seconds): int
    {
        $seconds = !empty($override_seconds) ? (int) $override_seconds : $default_seconds;
        return self::clamp_int($seconds, self::DURATION_MIN_SECONDS, self::DURATION_MAX_SECONDS) * 1000;
    }

    /**
     * Normalize AI prompt settings.
     *
     * A zero photo limit inherits the general limit at runtime. Terminal
     * punctuation defaults to enabled.
     *
     * @param array<string, mixed> $settings
     * @return array{word_limit: int, word_limit_photo: int, title_char_limit: int, min_input_words: int, ensure_terminal_period: bool}
     */
    public static function normalize_ai_prompt_settings(array $settings): array
    {
        $photo_word_limit = self::clamp_int($settings['word_limit_photo'] ?? 0, 0, 500);
        if ($photo_word_limit > 0) {
            $photo_word_limit = max(10, $photo_word_limit);
        }

        return [
            'word_limit' => self::clamp_int($settings['word_limit'] ?? 100, 10, 500),
            'word_limit_photo' => $photo_word_limit,
            'title_char_limit' => self::clamp_int($settings['title_char_limit'] ?? 40, 10, 100),
            'min_input_words' => self::clamp_int($settings['min_input_words'] ?? 50, 0, 500),
            'ensure_terminal_period' => !array_key_exists('ensure_terminal_period', $settings) || !empty($settings['ensure_terminal_period']),
        ];
    }

    /**
     * @return AiConfig
     */
    public static function get_ai_prompts(): array
    {
        $saved = get_option('teksttv_ai_prompts', []);
        $saved = is_array($saved) ? $saved : [];
        $limits = self::normalize_ai_prompt_settings($saved);
        if ($limits['word_limit_photo'] < 1) {
            $limits['word_limit_photo'] = $limits['word_limit'];
        }

        $defaults = [
            'system' => 'Je bent een eindredacteur voor tekst-tv. Schrijf in natuurlijk, vloeiend Nederlands voor een breed publiek. Gebruik korte, heldere zinnen. Schrijf alleen in het Nederlands en gebruik geen gedachtestreepjes.',
            'prompt_title' => 'Schrijf een korte, pakkende kop voor tekst-tv (maximaal {{chars}} tekens) gebaseerd op dit artikel. Geef alleen de kop terug, zonder aanhalingstekens.',
            'prompt_body' => 'Vat dit nieuwsartikel samen voor tekst-tv in maximaal {{words}} woorden. Schrijf in vloeiende, korte zinnen zonder HTML-opmaak.',
        ];

        return array_merge([
            'system' => !empty($saved['system']) ? $saved['system'] : $defaults['system'],
            'prompt_title' => !empty($saved['prompt_title']) ? $saved['prompt_title'] : $defaults['prompt_title'],
            'prompt_body' => !empty($saved['prompt_body']) ? $saved['prompt_body'] : $defaults['prompt_body'],
            'region_taxonomy' => $saved['region_taxonomy'] ?? '',
            'provider' => $saved['provider'] ?? '',
            'model' => $saved['model'] ?? '',
        ], $limits);
    }

    /**
     * @return array<string, array{label: string, models: array<string, string>}>
     */
    public static function get_ai_models(): array
    {
        if (!function_exists('wp_supports_ai') || !wp_supports_ai()) {
            return [];
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            $requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
                [\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration()],
                []
            );

            $result = [];
            foreach ($registry->findModelsMetadataForSupport($requirements) as $provider_models) {
                $provider = $provider_models->getProvider();
                $provider_id = $provider->getId();
                $models = [];
                foreach ($provider_models->getModels() as $model) {
                    $models[$model->getId()] = $model->getName();
                }
                if (!empty($models)) {
                    $result[$provider_id] = [
                        'label' => $provider->getName(),
                        'models' => $models,
                    ];
                }
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>> Block definitions.
     */
    public static function get_loop_config(string $channel_slug): array
    {
        return get_option('teksttv_loop_' . sanitize_key($channel_slug), []);
    }

    /**
     * @return list<array<string, mixed>> Ticker items.
     */
    public static function get_ticker_config(string $channel_slug): array
    {
        $items = get_option('teksttv_ticker_' . sanitize_key($channel_slug), []);
        return is_array($items) ? $items : [];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function get_commercial_blocks(): array
    {
        $commercial_blocks = get_option('teksttv_commercial_blocks', []);
        if (empty($commercial_blocks) || !is_array($commercial_blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($commercial_blocks as $commercial_block) {
            if (!is_array($commercial_block)) {
                continue;
            }
            $label = (string) ($commercial_block['label'] ?? '');
            $id = (string) ($commercial_block['id'] ?? '');
            if ($label === '' || $id === '') {
                continue;
            }
            $normalized[] = ['id' => $id, 'label' => $label];
        }

        return $normalized;
    }

    /**
     * Derive the stable commercial-block ID used by stored references.
     */
    public static function commercial_block_id(string $label): string
    {
        return 'grp_' . substr(md5($label), 0, 12);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function get_campaigns(): array
    {
        return get_option('teksttv_campaigns', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_active_campaigns(string $channel): array
    {
        $campaigns = self::get_campaigns();

        return array_filter($campaigns, function ($campaign) use ($channel) {
            $channels = $campaign['channels'] ?? [];
            if (!in_array($channel, $channels, true)) {
                return false;
            }

            return self::is_block_scheduled($campaign);
        });
    }

    public static function get_preview_url(): string
    {
        return get_option('teksttv_preview_url', '');
    }

    /**
     * Check a block's date and weekday constraints.
     *
     * @param array<string, mixed> $block Block or ticker item data.
     */
    public static function is_block_scheduled(array $block): bool
    {
        if (!self::is_within_date_range($block['date_start'] ?? null, $block['date_end'] ?? null)) {
            return false;
        }
        return self::is_allowed_on_day(self::normalize_days($block['days'] ?? null));
    }

    /**
     * Get post taxonomies and term labels, cached per request.
     *
     * @return list<array{name: string, label: string, terms: array<int, string>}>
     */
    public static function get_post_taxonomies(): array
    {
        if (self::$post_taxonomies_cache !== null) {
            return self::$post_taxonomies_cache;
        }

        $result = [];
        foreach (get_object_taxonomies('post', 'objects') as $tax) {
            if (!$tax->public || $tax->name === 'post_format') {
                continue;
            }

            // Avoid hydrating thousands of full WP_Term objects.
            $terms = get_terms([
                'taxonomy' => $tax->name,
                'hide_empty' => false,
                'fields' => 'id=>name',
            ]);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $result[] = [
                'name' => $tax->name,
                'label' => $tax->labels->singular_name,
                'terms' => $terms,
            ];
        }

        self::$post_taxonomies_cache = $result;
        return $result;
    }

    /**
     * @param array<string, mixed> $taxonomy_filters Keyed by taxonomy name, values are term ID arrays.
     * @return list<array{taxonomy: string, field: string, terms: list<int>}>
     */
    public static function build_tax_query(array $taxonomy_filters): array
    {
        $tax_query = [];
        foreach ($taxonomy_filters as $taxonomy => $term_ids) {
            $term_ids = (array) $term_ids;
            $term_ids = array_filter(array_map('intval', $term_ids));
            if (!empty($term_ids)) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_ids,
                ];
            }
        }
        return $tax_query;
    }

    /**
     * Build normalized image data for a semantic template slot.
     *
     * Themes can filter slot-specific URLs without coupling layout sizes to
     * this plugin. Known slots: image_slide and text_sidebar.
     *
     * @param int         $attachment_id The attachment post ID.
     * @param string      $size          Fallback WP image size used when no theme handles the slot.
     * @param string|null $slot          Optional semantic slot identifier (see list above).
     * @return array<string, string>|null Image data array or null if not found.
     */
    public static function get_image_data(
        int $attachment_id,
        string $size = 'large',
        ?string $slot = null
    ): ?array {
        $url = wp_get_attachment_image_url($attachment_id, $size);
        if (!$url) {
            return null;
        }

        $url = (string) apply_filters('teksttv_image_url', $url, $attachment_id, $slot);

        $data = ['url' => $url];

        $caption = wp_get_attachment_caption($attachment_id) ?: '';
        if (!empty($caption)) {
            $data['caption'] = $caption;
        }

        $attribution = apply_filters('teksttv_image_attribution', '', $attachment_id);
        if (!empty($attribution)) {
            $data['attribution'] = $attribution;
        }

        return $data;
    }

    /**
     * Count Unicode words, including Dutch text.
     */
    public static function count_words(string $text): int
    {
        return (int) preg_match_all('/\S+/', $text);
    }

    /**
     * Ensure generated body text ends with sentence-ending punctuation.
     */
    public static function ensure_terminal_period(string $text): string
    {
        $text = trim($text);
        if ($text === '' || preg_match('/[.!?…][\'"”’»)\]\}]*$/u', $text) === 1) {
            return $text;
        }

        // Normalize weak punctuation, keeping the period inside quotes and outside brackets.
        $text = preg_replace('/[,;:]+(?=[\'"”’»)\]\}]*$)/u', '', $text) ?? $text;

        return preg_replace('/([\'"”’»]*)$/u', '.$1', $text, 1) ?? $text . '.';
    }

    /**
     * Dependencies required by admin.js and wp.media.
     *
     * @return list<string>
     */
    public static function admin_script_dependencies(): array
    {
        return ['jquery', 'underscore', 'media-editor', 'wp-api-fetch'];
    }

    public static function enqueue_admin_script(): void
    {
        wp_enqueue_media();

        wp_enqueue_script(
            'teksttv-admin',
            TEKSTTV_PLUGIN_URL . 'assets/admin.js',
            self::admin_script_dependencies(),
            TEKSTTV_VERSION,
            true
        );

        wp_enqueue_style(
            'teksttv-admin',
            TEKSTTV_PLUGIN_URL . 'assets/admin.css',
            [],
            TEKSTTV_VERSION
        );

        if (wp_script_is('underscore', 'registered')) {
            wp_add_inline_script(
                'underscore',
                'if(typeof window._!=="undefined"&&typeof window._.defaults==="function"){window.wpUnderscore=window._;}',
                'after'
            );
        }
    }

    /**
     * Restore Underscore after late scripts overwrite `_`.
     */
    public static function print_underscore_restore(): void
    {
        if (!wp_script_is('teksttv-admin', 'enqueued')) {
            return;
        }

        wp_print_inline_script_tag(
            '(function(){var u=window.wpUnderscore;if(u&&(!window._||typeof window._.defaults!=="function")){window._=u;}})();'
        );
    }

    /**
     * Build a meta query for date ranges that include today.
     *
     * @return array<int|string, mixed>
     */
    public static function get_date_range_meta_query(): array
    {
        $today = current_datetime()->format('Y-m-d');
        return [
            'relation' => 'AND',
            [
                'relation' => 'OR',
                ['key' => '_teksttv_date_start', 'compare' => 'NOT EXISTS'],
                ['key' => '_teksttv_date_start', 'value' => '', 'compare' => '='],
                ['key' => '_teksttv_date_start', 'value' => $today, 'compare' => '<=', 'type' => 'DATE'],
            ],
            [
                'relation' => 'OR',
                ['key' => '_teksttv_date_end', 'compare' => 'NOT EXISTS'],
                ['key' => '_teksttv_date_end', 'value' => '', 'compare' => '='],
                ['key' => '_teksttv_date_end', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ],
        ];
    }
}
