<?php
/**
 * Settings page template.
 *
 * @var list<array{slug: string, label: string}> $channels
 * @var string $api_base_url
 * @var list<string> $features
 * @var list<array{name: string, label: string, terms: array<int, string>}> $all_taxonomies
 * @var list<string> $enabled_taxonomies
 */

namespace TekstTV;

defined('ABSPATH') || exit;

echo '<div class="wrap teksttv-admin">';
echo '<h1>' . esc_html('Tekst TV-instellingen') . '</h1>';

/**
 * Shared renderer for saved and template rows.
 *
 * @var callable(int|string, array{slug: string, label: string}): void $render_channel_row
 */
$render_channel_row = static function (int|string $i, array $ch) use ($api_base_url): void {
    $api_url = $ch['slug'] !== '' ? add_query_arg('channel', $ch['slug'], $api_base_url) : '';
    ?>
    <tr class="teksttv-channel-row">
        <td><label><span class="screen-reader-text teksttv-mobile-field-label"><?php echo esc_html('Slug'); ?></span><input type="text" name="teksttv_channels[<?php echo esc_attr((string) $i); ?>][slug]" value="<?php echo esc_attr($ch['slug']); ?>" class="regular-text" pattern="[a-z0-9_\-]+" required placeholder="<?php echo esc_attr('bijv. tv1'); ?>" autocomplete="off" spellcheck="false" /></label></td>
        <td><label><span class="screen-reader-text teksttv-mobile-field-label"><?php echo esc_html('Naam'); ?></span><input type="text" name="teksttv_channels[<?php echo esc_attr((string) $i); ?>][label]" value="<?php echo esc_attr($ch['label']); ?>" class="regular-text" required placeholder="<?php echo esc_attr('bijv. TV 1'); ?>" autocomplete="off" /></label></td>
        <td class="teksttv-channel-endpoint"><button type="button" class="button teksttv-copy-endpoint" data-endpoint="<?php echo esc_url($api_url); ?>" <?php disabled($api_url === ''); ?>><span class="teksttv-copy-endpoint-label" aria-live="polite"><?php echo esc_html('Link kopiëren'); ?></span></button></td>
        <td class="teksttv-table-actions"><button type="button" class="button-link button-link-delete teksttv-remove-channel"><?php echo esc_html('Verwijderen'); ?></button></td>
    </tr>
    <?php
};

/**
 * @var callable(string, string): void $render_feature_option
 */
$render_feature_option = static function (string $value, string $label) use ($features): void {
    ?>
    <label class="teksttv-feature-option">
        <input type="checkbox" name="teksttv_features[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $features, true)); ?> />
        <span><?php echo esc_html($label); ?></span>
    </label>
    <?php
};

?>
<div class="teksttv-tab-content" x-data="teksttvSettingsPage">
    <form method="post" action="options.php" class="teksttv-admin-column teksttv-settings-form">
        <?php settings_fields('teksttv_settings'); ?>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Kanalen'); ?></h2>
            <p class="description"><?php echo esc_html('Beheer de kanalen waarvoor Tekst TV-slides worden gegenereerd. Elk kanaal krijgt een eigen loop en API-endpoint.'); ?></p>
            <div class="teksttv-table-scroll">
                <table class="widefat teksttv-management-table" id="teksttv-channels" data-api-base="<?php echo esc_url($api_base_url); ?>" @click="channelsClick($event)" @input="channelsInput($event)">
                    <thead>
                        <tr>
                            <th><?php echo esc_html('Slug'); ?></th>
                            <th><?php echo esc_html('Naam'); ?></th>
                            <th><?php echo esc_html('API'); ?></th>
                            <th class="teksttv-table-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($channels as $i => $ch) :
                            $render_channel_row($i, $ch);
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="teksttv-add-block-bar teksttv-section-actions">
                <button type="button" class="button teksttv-add-action" id="teksttv-add-channel" @click.prevent="addChannelRow()"><?php echo esc_html('Kanaal toevoegen'); ?></button>
            </div>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Berichteditor'); ?></h2>
            <p class="description"><?php echo esc_html('Bepaal welke opties beschikbaar zijn bij het bewerken van een bericht.'); ?></p>
            <div class="teksttv-feature-groups">
                <section class="teksttv-feature-group">
                    <h3><?php echo esc_html('Inhoud & media'); ?></h3>
                    <p><?php echo esc_html('Extra mogelijkheden voor de inhoud van een bericht.'); ?></p>
                    <fieldset class="teksttv-feature-options">
                        <legend class="screen-reader-text"><?php echo esc_html('Inhoud en media'); ?></legend>
                        <?php $render_feature_option('custom_title', 'Kop overschrijven'); ?>
                        <?php $render_feature_option('sidebar_image', 'Sidebarafbeelding kiezen'); ?>
                        <?php $render_feature_option('extra_images', 'Extra afbeeldingen'); ?>
                    </fieldset>
                </section>

                <section class="teksttv-feature-group">
                    <h3><?php echo esc_html('Publicatie'); ?></h3>
                    <p><?php echo esc_html('Bepaal wanneer en hoe berichten worden getoond.'); ?></p>
                    <fieldset class="teksttv-feature-options">
                        <legend class="screen-reader-text"><?php echo esc_html('Publicatie'); ?></legend>
                        <?php $render_feature_option('scheduling', 'Planning'); ?>
                        <?php $render_feature_option('page_separator', 'Meerdere slides'); ?>
                    </fieldset>
                </section>

                <section class="teksttv-feature-group">
                    <h3><?php echo esc_html('Tekstopmaak'); ?></h3>
                    <p><?php echo esc_html('Kies welke opmaak beschikbaar is in de editor.'); ?></p>
                    <fieldset class="teksttv-feature-options">
                        <legend class="screen-reader-text"><?php echo esc_html('Tekstopmaak'); ?></legend>
                        <?php $render_feature_option('bold', 'Vetgedrukt'); ?>
                        <?php $render_feature_option('italic', 'Cursief'); ?>
                        <?php $render_feature_option('underline', 'Onderstrepen'); ?>
                        <?php $render_feature_option('lists', 'Lijsten'); ?>
                    </fieldset>
                </section>

                <section class="teksttv-feature-group">
                    <h3><?php echo esc_html('AI-assistent'); ?></h3>
                    <p><?php echo esc_html('Ondersteuning voor het schrijven van Tekst TV-teksten.'); ?></p>
                    <fieldset class="teksttv-feature-options">
                        <legend class="screen-reader-text"><?php echo esc_html('AI-assistent'); ?></legend>
                        <?php $render_feature_option('ai_generate', 'Tekst genereren'); ?>
                    </fieldset>
                </section>
            </div>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('RSS'); ?></h2>
            <p class="description"><?php echo esc_html('Voeg de Tekst TV-kop, tekst en afbeeldingen toe aan bestaande RSS 2.0-feeds. Alleen gepubliceerde berichten zonder wachtwoord met "Toon op Tekst TV" aan en binnen de maximale leeftijd krijgen deze extra velden; staat Planning aan, dan alleen binnen de ingestelde datums en dagen. RSS blijft cachebaar: ongewijzigde aanvragen geven 304. Wijzigingen en de dagelijkse datumwissel verversen de cachevalidatie; laat een CDN bij elke poll opnieuw valideren.'); ?></p>
            <?php $render_feature_option('rss_feed', 'TekstTV opnemen in RSS'); ?>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Slideduur'); ?></h2>
            <p class="description"><?php echo esc_html('Standaard weergaveduur per type slide. Kan per bericht worden overschreven.'); ?></p>
            <table class="form-table teksttv-form-table">
                <tr>
                    <th scope="row"><label for="teksttv_duration_text"><?php echo esc_html('Tekstslide'); ?></label></th>
                    <td>
                        <input type="number" id="teksttv_duration_text" name="teksttv_duration_text" value="<?php echo esc_attr(get_option('teksttv_duration_text', Helpers::DURATION_DEFAULTS['teksttv_duration_text'])); ?>" min="<?php echo esc_attr((string) Helpers::DURATION_MIN_SECONDS); ?>" max="<?php echo esc_attr((string) Helpers::DURATION_MAX_SECONDS); ?>" class="small-text" /> <?php echo esc_html('seconden'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="teksttv_duration_image"><?php echo esc_html('Afbeeldingslide'); ?></label></th>
                    <td>
                        <input type="number" id="teksttv_duration_image" name="teksttv_duration_image" value="<?php echo esc_attr(get_option('teksttv_duration_image', Helpers::DURATION_DEFAULTS['teksttv_duration_image'])); ?>" min="<?php echo esc_attr((string) Helpers::DURATION_MIN_SECONDS); ?>" max="<?php echo esc_attr((string) Helpers::DURATION_MAX_SECONDS); ?>" class="small-text" /> <?php echo esc_html('seconden'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="teksttv_duration_iframe"><?php echo esc_html('Iframe-slide'); ?></label></th>
                    <td>
                        <input type="number" id="teksttv_duration_iframe" name="teksttv_duration_iframe" value="<?php echo esc_attr(get_option('teksttv_duration_iframe', Helpers::DURATION_DEFAULTS['teksttv_duration_iframe'])); ?>" min="<?php echo esc_attr((string) Helpers::DURATION_MIN_SECONDS); ?>" max="<?php echo esc_attr((string) Helpers::DURATION_MAX_SECONDS); ?>" class="small-text" /> <?php echo esc_html('seconden'); ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Taxonomiefilters'); ?></h2>
            <p class="description"><?php echo esc_html('Kies welke taxonomieën beschikbaar zijn als filter in de loopblokken.'); ?></p>
            <fieldset class="teksttv-checkbox-list">
                <?php foreach ($all_taxonomies as $tax) : ?>
                <label class="teksttv-checkbox-list-item">
                    <input type="checkbox" name="teksttv_enabled_taxonomies[]" value="<?php echo esc_attr($tax['name']); ?>" <?php checked(in_array($tax['name'], $enabled_taxonomies, true)); ?> />
                    <?php echo esc_html($tax['label']); ?> <code><?php echo esc_html($tax['name']); ?></code>
                </label>
                <?php endforeach; ?>
            </fieldset>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Standaardwaarden'); ?></h2>
            <p class="description"><?php echo esc_html('Standaardinstellingen voor nieuwe Tekst TV-items bij berichten.'); ?></p>
            <table class="form-table teksttv-form-table">
                <tr>
                    <th scope="row"><label for="teksttv_default_end_days"><?php echo esc_html('Standaard einddatum'); ?></label></th>
                    <td>
                        <input type="number" id="teksttv_default_end_days" name="teksttv_default_end_days" value="<?php echo esc_attr(get_option('teksttv_default_end_days', 7)); ?>" min="0" max="365" class="small-text" />
                        <?php echo esc_html('dagen na publicatiedatum'); ?>
                        <p class="description"><?php echo esc_html('Stel 0 in om geen standaard einddatum te gebruiken.'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="teksttv_max_post_age"><?php echo esc_html('Maximale leeftijd berichten'); ?></label></th>
                    <td>
                        <input type="number" id="teksttv_max_post_age" name="teksttv_max_post_age" value="<?php echo esc_attr(get_option('teksttv_max_post_age', 30)); ?>" min="0" max="365" class="small-text" />
                        <?php echo esc_html('dagen'); ?>
                        <p class="description"><?php echo esc_html('Berichten ouder dan dit worden niet meegenomen. Stel 0 in voor geen limiet.'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Weer'); ?></h2>
            <p class="description"><?php echo esc_html('OpenWeather-API-configuratie voor weerslides.'); ?></p>
            <table class="form-table teksttv-form-table">
                <tr>
                    <th scope="row"><label for="teksttv_openweather_api_key"><?php echo esc_html('API-sleutel'); ?></label></th>
                    <td>
                        <input type="password" id="teksttv_openweather_api_key" name="teksttv_openweather_api_key" value="<?php echo esc_attr(get_option('teksttv_openweather_api_key', '')); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description"><?php echo wp_kses('OneCall API 3.0 key van <a href="https://openweathermap.org/api" target="_blank" rel="noopener">openweathermap.org</a>.', ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="teksttv-card">
            <h2><?php echo esc_html('Preview'); ?></h2>
            <p class="description"><?php echo esc_html('Configureer de live preview die getoond wordt bij het bewerken van posts.'); ?></p>
            <table class="form-table teksttv-form-table">
                <tr>
                    <th scope="row"><label for="teksttv_preview_url"><?php echo esc_html('Preview-URL'); ?></label></th>
                    <td>
                        <input type="url" id="teksttv_preview_url" name="teksttv_preview_url" value="<?php echo esc_attr(Helpers::get_preview_url()); ?>" class="large-text" placeholder="https://teksttv.example.com/zuidwest-1/preview" spellcheck="false" />
                        <p class="description"><?php echo esc_html('De volledige URL naar de previewpagina van de Tekst TV-frontend.'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <?php AdminPage::render_form_actions(); ?>
    </form>

    <template id="tmpl-teksttv-channel-row">
        <?php $render_channel_row(0, ['slug' => '', 'label' => '']); ?>
    </template>
</div>
<?php

echo '</div>';
