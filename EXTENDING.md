# Extending TekstTV

TekstTV can be extended from a separate WordPress plugin. Do not modify the TekstTV plugin itself: register block types through `TekstTV\BlockRegistry` and use the filters listed below.

## Extension points

| API | Purpose |
|---|---|
| `TekstTV\BlockRegistry::register()` | Add a loop block or ticker type |
| `teksttv_image_url` | Replace an attachment URL for a specific image slot |
| `teksttv_image_attribution` | Add attribution to attachment data |
| `teksttv_primary_category` | Select the category used for an article sidebar image |
| `teksttv_weather_provider` | Replace the OpenWeather implementation |

Built-in block types register on `init` at priority 5. Add-ons should register at priority 10 or later and guard against TekstTV being unavailable:

```php
add_action('init', static function (): void {
    if (!class_exists(\TekstTV\BlockRegistry::class)) {
        return;
    }

    // Register add-on types here.
}, 10);
```

For a single-file add-on, add `Requires Plugins: teksttv` to its plugin header. This slug assumes TekstTV is installed in `wp-content/plugins/teksttv/`; adjust it when a source installation uses another directory name. Add-ons with classes must provide their own autoloader.

## Loop and ticker types

Register a type with a unique, prefixed slug and these arguments:

| Argument | Description |
|---|---|
| `label` | Label shown in the admin selector |
| `icon` | Dashicon name without the `dashicons-` prefix |
| `color` | Icon background colour |
| `context` | `loop`, `ticker`, or `both` |
| `render` | Renders fields for one admin row |
| `save` | Sanitizes one submitted row |
| `build` | Produces REST output for one stored row |

Each callback may be any PHP callable with these signatures:

```php
render(int|string $index, array $data, string $prefix): void
save(array $raw): ?array
build(array $data, string $channel): array
```

`$prefix` is `teksttv_blocks` for a loop block and `teksttv_ticker` for a ticker type. Use it in every field name. The registry adds the `type` key to saved data, so the `save` callback should not add it. Return `null` from `save` to discard an empty or invalid row.

To show a live value in the collapsed row summary, add `data-summary` to a field rendered by the callback. Its optional value is a format string containing `%s`, for example `data-summary="%sx"`. Use `data-summary-label` to replace a non-empty value with a fixed label, and `data-summary-empty` to supply a fallback for empty values.

Date and weekday scheduling fields are added and persisted by TekstTV. Both loop and ticker schedules are checked before the build callback runs.

A registered type automatically appears in the matching admin selector and is processed by the REST pipeline.

`context => both` exposes the type in both selectors. The `build` callback does not receive the active context, so use `both` only when its stored data and output work in both pipelines.

### Ticker example

This complete single-file plugin adds a configurable ticker message:

```php
<?php
/**
 * Plugin Name: TekstTV Station Ticker
 * Requires Plugins: teksttv
 */

add_action('init', static function (): void {
    if (!class_exists(\TekstTV\BlockRegistry::class)) {
        return;
    }

    \TekstTV\BlockRegistry::register('station_ticker_message', [
        'label' => 'Stationsbericht',
        'icon' => 'megaphone',
        'color' => '#2271b1',
        'context' => 'ticker',
        'render' => static function (int|string $index, array $data, string $prefix): void {
            $name = sprintf('%s[%s][message]', $prefix, $index);
            ?>
            <label>
                <?php echo esc_html('Bericht'); ?>
                <input
                    type="text"
                    class="large-text"
                    name="<?php echo esc_attr($name); ?>"
                    value="<?php echo esc_attr((string) ($data['message'] ?? '')); ?>"
                />
            </label>
            <?php
        },
        'save' => static function (array $raw): ?array {
            $message = sanitize_text_field($raw['message'] ?? '');

            return $message === '' ? null : ['message' => $message];
        },
        'build' => static function (array $data, string $channel): array {
            $message = (string) ($data['message'] ?? '');

            return $message === '' ? [] : [['message' => $message]];
        },
    ]);
}, 10);
```

Ticker builders must return a list of entries containing a string `message`:

```php
[
    ['message' => 'First message'],
    ['message' => 'Second message'],
]
```

### Loop output

Loop builders return a list of slides. Each slide must match a type supported by the [TekstTV frontend](https://github.com/oszuidwest/teksttv-frontend). Durations are expressed in milliseconds.

## Image data

`TekstTV\Helpers::get_image_data()` applies two filters. Known image slots are `image_slide` and `text_sidebar`.

### Image URL

```php
add_filter(
    'teksttv_image_url',
    static function (string $url, int $attachment_id, ?string $slot): string {
        if ($slot !== 'image_slide') {
            return $url;
        }

        $replacement = wp_get_attachment_image_url($attachment_id, 'full');

        return $replacement ?: $url;
    },
    10,
    3
);
```

The callback receives the fallback URL, attachment ID and semantic slot. Return a URL string. Returning `''` omits the image from article slides and the RSS export; an empty `text_sidebar` URL removes the sidebar image instead of falling back to the next source.

### Image attribution

```php
add_filter(
    'teksttv_image_attribution',
    static function (string $attribution, int $attachment_id): string {
        $credit = get_post_meta($attachment_id, '_photo_credit', true);

        return $credit !== '' ? (string) $credit : $attribution;
    },
    10,
    2
);
```

Non-empty attribution is added to image data as `attribution`.

## Primary category

The article block uses a primary category when resolving its sidebar image. The default is the Yoast `_yoast_wpseo_primary_category` post meta value.

```php
add_filter(
    'teksttv_primary_category',
    static function (int|string|false $term_id, int $post_id): int|string|false {
        $custom_term_id = get_post_meta($post_id, '_station_primary_category', true);

        return $custom_term_id !== '' ? $custom_term_id : $term_id;
    },
    10,
    2
);
```

Return a category term ID or `false` to continue with the post's assigned categories and featured image.

## Weather provider

Implement `TekstTV\WeatherProvider` and replace the default provider with `teksttv_weather_provider`:

```php
add_filter(
    'teksttv_weather_provider',
    static fn(?\TekstTV\WeatherProvider $default): \TekstTV\WeatherProvider => new StationWeatherProvider(),
    10,
    1
);
```

`fetch(string $location): ?array` must return this normalized shape or `null` on failure:

```php
[
    'city' => 'Breda',
    'days' => [
        [
            'date' => new DateTime('2026-07-13'),
            'temp_min' => 14.2,
            'temp_max' => 23.8,
            'weather_id' => 800,
            'icon' => '01d',
            'description' => 'Helder',
            'wind_speed' => 3.4,
            'wind_deg' => 225,
        ],
    ],
]
```

The provider is resolved once per request. An add-on provider may implement its own caching and error handling.

## REST output

The `/teksttv/v1/slides` response is built fresh on every request; there is no plugin-side cache. The former `\TekstTV\RestApi::invalidate_slides_cache()` API has been removed - add-ons that still call it will fatal, so drop the call. Block `build()` callbacks that call slow external services should do their own caching (see the weather provider note above).

## Current limitations

- There is no dedicated block-registration action; add-ons call `BlockRegistry` on WordPress `init`.
- There is no filter for the final slides array, ticker array or complete REST payload.
- Registering an existing slug silently replaces that type for the current request.
- There is no unregister method.
- If an add-on is disabled, its stored rows cannot be rendered. TekstTV preserves those rows when the channel is saved so they become available again when the type is registered.
- Registry definitions are not validated when registered. Callback availability and saved array data are checked only when used.

Treat the registry as the current extension API and test add-ons against the TekstTV versions they support.

## Verification checklist

- Activate TekstTV and the add-on without PHP notices or fatal errors.
- Confirm the type appears in the expected selector.
- Save and reload the channel configuration.
- Check scheduling boundaries when the type uses scheduling.
- Validate the `/wp-json/teksttv/v1/slides?channel=<slug>` response against the frontend contract.
