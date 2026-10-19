import { login } from './helpers';
import { reseedFixtures } from './reseed-fixtures';
import { expect, type Page, test } from './test';

const XML_NAMESPACE = 'https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0';

async function readFeed(page: Page, xml: string) {
    return page.evaluate(
        ({ xml, namespace }) => {
            const document = new DOMParser().parseFromString(xml, 'application/xml');
            if (document.querySelector('parsererror')) throw new Error('Invalid RSS XML');
            return Array.from(document.querySelectorAll('item'), (item) => ({
                title: item.querySelector('title')?.textContent,
                teksttv: Array.from(item.getElementsByTagNameNS(namespace, '*'), (field) => ({
                    name: field.localName,
                    value: field.textContent,
                })),
            }));
        },
        { xml, namespace: XML_NAMESPACE },
    );
}

test.describe('RSS 2.0 export', () => {
    test.use({ storageState: { cookies: [], origins: [] } });
    test.afterEach(reseedFixtures);

    test('settings persist the opt-in and invalidate both enabling and disabling export', async ({ page, request }) => {
        const ordinary = await request.get('/?feed=rss2');
        expect(ordinary.status()).toBe(200);
        expect(await ordinary.text()).not.toContain('xmlns:teksttv');
        const etag = ordinary.headers().etag;
        expect(etag).toBeTruthy();

        await login(page, 'admin', 'password');
        await page.goto('/wp-admin/admin.php?page=teksttv-settings');
        const toggle = page.getByRole('checkbox', { name: 'TekstTV opnemen in RSS' });
        await expect(toggle).not.toBeChecked();
        await toggle.check();
        await page.click('#submit');
        await expect(toggle).toBeChecked();

        const enabled = await request.get('/?feed=rss2', {
            headers: { 'If-None-Match': etag, 'If-Modified-Since': 'Wed, 01 Jan 2098 00:00:00 GMT' },
        });
        expect(enabled.status()).toBe(200);
        expect(enabled.headers()['cache-control'] ?? '').not.toContain('no-store');
        expect(enabled.headers().etag).toBeTruthy();
        expect(enabled.headers()['last-modified']).toBeTruthy();
        expect(await enabled.text()).toContain(`xmlns:teksttv="${XML_NAMESPACE}"`);
        expect(
            (await request.get('/?feed=rss2', { headers: { 'If-None-Match': enabled.headers().etag } })).status(),
        ).toBe(304);

        await toggle.uncheck();
        await page.click('#submit');
        await expect(toggle).not.toBeChecked();
        const disabled = await request.get('/?feed=rss2', { headers: { 'If-None-Match': enabled.headers().etag } });
        expect(disabled.status()).toBe(200);
        expect(await disabled.text()).not.toContain('xmlns:teksttv');
        expect(disabled.headers().etag).toBeTruthy();
        const cached = await request.get('/?feed=rss2', { headers: { 'If-None-Match': disabled.headers().etag } });
        expect(cached.status()).toBe(304);
    });

    test('exports namespaced title, formatted pages and images through category and tag feeds', async ({
        request,
        page,
        runWordPressPHP,
    }) => {
        const fixture = JSON.parse(
            await runWordPressPHP(`
                update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
                update_option('posts_per_rss', 100);
                $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
                $image = get_posts(['post_type' => 'attachment', 'numberposts' => 1])[0];
                update_post_meta($post->ID, '_teksttv_title', 'Kop & café');
                update_post_meta($post->ID, '_teksttv_content', "<p>Eerste &amp; slide ]]>.</p><p>---</p><p>Tweede slide.</p>");
                update_post_meta($post->ID, '_teksttv_sidebar_image', $image->ID);
                update_post_meta($post->ID, '_teksttv_images', [$image->ID, 999999, $image->ID]);
                wp_set_post_tags($post->ID, ['RSS Export']);
                echo wp_json_encode([
                    'image' => wp_get_attachment_image_url($image->ID, 'large'),
                    'category' => wp_get_post_categories($post->ID)[0],
                    'tag' => wp_get_post_tags($post->ID)[0]->term_id,
                ]);
            `),
        );

        for (const feed of [
            '/feed/',
            '/?feed=rss2',
            `/?feed=rss2&cat=${fixture.category}`,
            `/?feed=rss2&tag_id=${fixture.tag}`,
        ]) {
            const response = await request.get(feed);
            expect(response.status()).toBe(200);
            const items = await readFeed(page, await response.text());
            const post = items.find((item) => item.title === 'TekstTV Smoke Post');
            expect(post?.teksttv).toEqual([
                { name: 'title', value: 'Kop & café' },
                { name: 'content', value: '<p>Eerste &amp; slide ]]>.</p>\n\n<hr />\n<p>Tweede slide.</p>\n' },
                { name: 'image', value: fixture.image },
                { name: 'extraImage', value: fixture.image },
                { name: 'extraImage', value: fixture.image },
            ]);
        }
    });

    test('re-evaluates expiry and metadata changes despite old conditional request headers', async ({
        request,
        page,
        runWordPressPHP,
    }) => {
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            update_option('posts_per_rss', 100);
            update_option('timezone_string', 'Europe/Amsterdam');
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_title', 'Nu geldig');
        `);
        const first = await request.get('/?feed=rss2');
        expect(first.status()).toBe(200);
        expect(await first.text()).toContain('<teksttv:title>Nu geldig</teksttv:title>');
        const headers = {
            'If-None-Match': first.headers().etag,
            'If-Modified-Since': first.headers()['last-modified'],
        };
        expect((await request.get('/?feed=rss2', { headers })).status()).toBe(304);

        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_date_end', current_datetime()->modify('-1 day')->format('Y-m-d'));
        `);
        const expired = await request.get('/?feed=rss2', { headers });
        expect(expired.status()).toBe(200);
        const items = await readFeed(page, await expired.text());
        expect(items.find((item) => item.title === 'TekstTV Smoke Post')?.teksttv).toEqual([]);

        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_date_end', current_time('Y-m-d'));
            update_post_meta($post->ID, '_teksttv_title', 'Opnieuw geldig');
        `);
        const restored = await request.get('/?feed=rss2', { headers });
        expect(restored.status()).toBe(200);
        expect(await restored.text()).toContain('<teksttv:title>Opnieuw geldig</teksttv:title>');
    });

    test('post saves and category image changes invalidate conditional requests', async ({
        request,
        runWordPressPHP,
    }) => {
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            update_option('posts_per_rss', 100);
        `);
        const first = await request.get('/?feed=rss2');
        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            wp_update_post(['ID' => $post->ID, 'post_title' => 'Gewijzigd RSS artikel']);
        `);
        const saved = await request.get('/?feed=rss2', { headers: { 'If-None-Match': first.headers().etag } });
        expect(saved.status()).toBe(200);
        expect(await saved.text()).toContain('Gewijzigd RSS artikel');

        const imageUrl = await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            delete_post_meta($post->ID, '_teksttv_sidebar_image');
            delete_post_meta($post->ID, '_thumbnail_id');
            $term = wp_get_post_categories($post->ID)[0];
            delete_term_meta($term, '_teksttv_category_image');
            $image = get_posts(['post_type' => 'attachment', 'numberposts' => 1])[0];
            echo wp_get_attachment_image_url($image->ID, 'large');
        `);
        const withoutImage = await request.get('/?feed=rss2');
        expect(await withoutImage.text()).not.toContain('<teksttv:image>');
        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            $image = get_posts(['post_type' => 'attachment', 'numberposts' => 1])[0];
            update_term_meta(wp_get_post_categories($post->ID)[0], '_teksttv_category_image', $image->ID);
        `);
        const changed = await request.get('/?feed=rss2', { headers: { 'If-None-Match': withoutImage.headers().etag } });
        expect(changed.status()).toBe(200);
        expect(await changed.text()).toContain(`<teksttv:image>${imageUrl}</teksttv:image>`);
        expect(
            (
                await request.get('/?feed=rss2', {
                    headers: { 'If-Modified-Since': changed.headers()['last-modified'] },
                })
            ).status(),
        ).toBe(304);
    });

    test('site midnight advances validators and re-evaluates scheduling', async ({
        request,
        page,
        runWordPressPHP,
    }) => {
        // A temporary mu-plugin supplies a deterministic clock to both scheduling and validators.
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            update_option('posts_per_rss', 100);
            update_option('timezone_string', 'Europe/Amsterdam');
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_date_end', '2098-10-25');
            update_option('teksttv_max_post_age', 0);
            update_option('teksttv_test_clock', '2098-10-25 23:59:59');
            wp_mkdir_p(WPMU_PLUGIN_DIR);
            file_put_contents(WPMU_PLUGIN_DIR . '/rss-clock.php', '<?php namespace TekstTV; function current_datetime() { return new \\DateTimeImmutable(\\get_option("teksttv_test_clock"), new \\DateTimeZone("Europe/Amsterdam")); }');
        `);
        try {
            const before = await request.get('/?feed=rss2');
            const beforeItems = await readFeed(page, await before.text());
            expect(beforeItems.find((item) => item.title === 'TekstTV Smoke Post')?.teksttv.length).toBeGreaterThan(0);
            expect(
                (await request.get('/?feed=rss2', { headers: { 'If-None-Match': before.headers().etag } })).status(),
            ).toBe(304);
            await runWordPressPHP("update_option('teksttv_test_clock', '2098-10-26 00:00:00');");
            const after = await request.get('/?feed=rss2', { headers: { 'If-None-Match': before.headers().etag } });
            expect(after.status()).toBe(200);
            expect(after.headers()['last-modified']).not.toBe(before.headers()['last-modified']);
            const afterItems = await readFeed(page, await after.text());
            expect(afterItems.find((item) => item.title === 'TekstTV Smoke Post')?.teksttv).toEqual([]);
        } finally {
            await runWordPressPHP(
                "unlink(WPMU_PLUGIN_DIR . '/rss-clock.php'); delete_option('teksttv_test_clock'); update_option('teksttv_max_post_age', 30);",
            );
        }
    });

    test('Atom and comment feeds remain unchanged when RSS export is enabled', async ({ request, runWordPressPHP }) => {
        await runWordPressPHP(
            "update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);",
        );
        for (const feed of ['/?feed=atom', '/?feed=rss2&withcomments=1']) {
            const response = await request.get(feed);
            expect(response.status()).toBe(200);
            expect(await response.text()).not.toContain('xmlns:teksttv');
            const etag = response.headers().etag;
            expect(etag).toBeTruthy();
            const cached = await request.get(feed, { headers: { 'If-None-Match': etag } });
            expect(cached.status()).toBe(304);
        }
    });

    test('password-protected posts receive no TekstTV fields', async ({ request, page, runWordPressPHP }) => {
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            update_option('posts_per_rss', 100);
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            wp_update_post(['ID' => $post->ID, 'post_password' => 'geheim']);
        `);
        const items = await readFeed(page, await (await request.get('/?feed=rss2')).text());
        // Core prefixes protected titles, e.g. "Protected: TekstTV Smoke Post".
        const post = items.find((item) => item.title?.endsWith('TekstTV Smoke Post'));
        expect(post).toBeDefined();
        expect(post?.teksttv).toEqual([]);
    });
});
