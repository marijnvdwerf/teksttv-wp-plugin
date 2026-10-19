import type { APIRequestContext, APIResponse } from '@playwright/test';
import { login } from './helpers';
import { reseedFixtures } from './reseed-fixtures';
import { expect, type Page, type RunWordPressPHP, test } from './test';

const XML_NAMESPACE = 'https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0';

/** Validators never run ahead of the clock, so wait until the stored revision is in the past. */
async function settle(runWordPressPHP: RunWordPressPHP) {
    const ahead = Number(await runWordPressPHP("echo max(0, (int) get_option('teksttv_rss_modified') - time());"));
    await new Promise((resolve) => setTimeout(resolve, ahead * 1000 + 1050 - (Date.now() % 1000)));
}

/** Request the RSS feed again with an earlier response's ETag, Last-Modified or both. */
function revalidate(request: APIRequestContext, previous: APIResponse, by: 'etag' | 'date' | 'both' = 'etag') {
    const { etag, 'last-modified': date } = previous.headers();
    return request.get('/?feed=rss2', {
        headers: {
            ...(by !== 'date' && { 'If-None-Match': etag }),
            ...(by !== 'etag' && { 'If-Modified-Since': date }),
        },
    });
}

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

    test('settings persist the opt-in and invalidate both enabling and disabling export', async ({
        page,
        request,
        runWordPressPHP,
    }) => {
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

        await settle(runWordPressPHP);

        const enabled = await request.get('/?feed=rss2', {
            headers: { 'If-None-Match': etag, 'If-Modified-Since': 'Wed, 01 Jan 2098 00:00:00 GMT' },
        });
        expect(enabled.status()).toBe(200);
        expect(enabled.headers()['cache-control'] ?? '').not.toContain('no-store');
        expect(enabled.headers().etag).toBeTruthy();
        expect(enabled.headers()['last-modified']).toBeTruthy();
        expect(await enabled.text()).toContain(`xmlns:teksttv="${XML_NAMESPACE}"`);
        expect((await revalidate(request, enabled)).status()).toBe(304);

        await toggle.uncheck();
        await page.click('#submit');
        await expect(toggle).not.toBeChecked();
        await settle(runWordPressPHP);
        const disabled = await revalidate(request, enabled);
        expect(disabled.status()).toBe(200);
        expect(await disabled.text()).not.toContain('xmlns:teksttv');
        expect(disabled.headers().etag).toBeTruthy();
        const cached = await revalidate(request, disabled);
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

    test('metadata writes without a post save invalidate conditional requests', async ({
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
        await settle(runWordPressPHP);
        const first = await request.get('/?feed=rss2');
        expect(first.status()).toBe(200);
        expect(await first.text()).toContain('<teksttv:title>Nu geldig</teksttv:title>');
        expect((await revalidate(request, first, 'both')).status()).toBe(304);

        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_date_end', current_datetime()->modify('-1 day')->format('Y-m-d'));
        `);
        await settle(runWordPressPHP);
        const expired = await revalidate(request, first, 'both');
        expect(expired.status()).toBe(200);
        const items = await readFeed(page, await expired.text());
        expect(items.find((item) => item.title === 'TekstTV Smoke Post')?.teksttv).toEqual([]);

        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_date_end', current_time('Y-m-d'));
            update_post_meta($post->ID, '_teksttv_title', 'Opnieuw geldig');
        `);
        await settle(runWordPressPHP);
        const restored = await revalidate(request, expired, 'both');
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
        await settle(runWordPressPHP);
        const first = await request.get('/?feed=rss2');
        expect((await revalidate(request, first)).status()).toBe(304);
        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            wp_update_post(['ID' => $post->ID, 'post_title' => 'Gewijzigd RSS artikel']);
        `);
        await settle(runWordPressPHP);
        const saved = await revalidate(request, first);
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
        await settle(runWordPressPHP);
        const withoutImage = await request.get('/?feed=rss2');
        expect(await withoutImage.text()).not.toContain('<teksttv:image>');
        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            $image = get_posts(['post_type' => 'attachment', 'numberposts' => 1])[0];
            update_term_meta(wp_get_post_categories($post->ID)[0], '_teksttv_category_image', $image->ID);
        `);
        await settle(runWordPressPHP);
        const changed = await revalidate(request, withoutImage);
        expect(changed.status()).toBe(200);
        expect(await changed.text()).toContain(`<teksttv:image>${imageUrl}</teksttv:image>`);
        expect((await revalidate(request, changed, 'date')).status()).toBe(304);

        // Category assignment without a post save changes the sidebar image too.
        const term = await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            $term = wp_get_post_categories($post->ID)[0];
            wp_remove_object_terms($post->ID, $term, 'category');
            echo $term;
        `);
        await settle(runWordPressPHP);
        const removed = await revalidate(request, changed, 'date');
        expect(removed.status()).toBe(200);
        expect(await removed.text()).not.toContain('<teksttv:image>');
        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            wp_set_object_terms($post->ID, [${Number(term)}], 'category');
        `);
        await settle(runWordPressPHP);
        const assigned = await revalidate(request, removed, 'date');
        expect(assigned.status()).toBe(200);
        expect(await assigned.text()).toContain(`<teksttv:image>${imageUrl}</teksttv:image>`);
    });

    test('unpublishing the newest post invalidates validators that core would move back', async ({
        request,
        runWordPressPHP,
    }) => {
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            update_option('posts_per_rss', 100);
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            wp_update_post(['ID' => $post->ID, 'post_title' => 'Nieuwste RSS artikel']);
        `);
        await settle(runWordPressPHP);
        const first = await request.get('/?feed=rss2');
        expect(await first.text()).toContain('Nieuwste RSS artikel');
        await runWordPressPHP(`
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
        `);
        await settle(runWordPressPHP);
        const unpublished = await revalidate(request, first, 'date');
        expect(unpublished.status()).toBe(200);
        expect(await unpublished.text()).not.toContain('Nieuwste RSS artikel');
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
        const before = await request.get('/?feed=rss2');
        const beforeItems = await readFeed(page, await before.text());
        expect(beforeItems.find((item) => item.title === 'TekstTV Smoke Post')?.teksttv.length).toBeGreaterThan(0);
        expect((await revalidate(request, before)).status()).toBe(304);
        await runWordPressPHP("update_option('teksttv_test_clock', '2098-10-26 00:00:00');");
        const after = await revalidate(request, before);
        expect(after.status()).toBe(200);
        expect(after.headers()['last-modified']).not.toBe(before.headers()['last-modified']);
        const afterItems = await readFeed(page, await after.text());
        expect(afterItems.find((item) => item.title === 'TekstTV Smoke Post')?.teksttv).toEqual([]);
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

    test('corrupt TekstTV meta skips the item and keeps the feed well-formed', async ({
        request,
        page,
        runWordPressPHP,
    }) => {
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            update_option('posts_per_rss', 100);
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            update_post_meta($post->ID, '_teksttv_content', new stdClass());
        `);
        const xml = await (await request.get('/?feed=rss2')).text();
        expect(xml).toContain('</rss>');
        const items = await readFeed(page, xml);
        const post = items.find((item) => item.title === 'TekstTV Smoke Post');
        expect(post).toBeDefined();
        expect(post?.teksttv).toEqual([]);
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
