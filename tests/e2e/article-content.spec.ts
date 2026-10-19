import { reseedFixtures } from './reseed-fixtures';
import { expect, test } from './test';

test.afterEach(reseedFixtures);

test('playout and RSS apply the shared article rules with explicit password policy', async ({ runWordPressPHP }) => {
    const results = JSON.parse(
        await runWordPressPHP(`
            update_option('teksttv_features', [...\\TekstTV\\Helpers::DEFAULT_FEATURES, 'rss_feed']);
            $post = get_page_by_path('teksttv-smoke-post', OBJECT, 'post');
            $image = get_posts(['post_type' => 'attachment', 'numberposts' => 1])[0];
            $defaults = [
                '_teksttv_active' => '1', '_teksttv_title' => 'Gedeelde kop',
                '_teksttv_content' => '<p>Eerste</p><p>---</p><p>Tweede</p>',
                '_teksttv_date_start' => '', '_teksttv_date_end' => '',
                '_teksttv_days' => [current_datetime()->format('N')],
                '_teksttv_sidebar_image' => $image->ID, '_teksttv_images' => [$image->ID],
            ];
            $cases = [
                'eligible' => [], 'inactive' => ['_teksttv_active' => '0'],
                'invalid date' => ['_teksttv_date_end' => 'invalid'],
                'impossible date' => ['_teksttv_date_start' => '2026-02-31'],
                'expired' => ['_teksttv_date_end' => current_datetime()->modify('-1 day')->format('Y-m-d')],
                'future' => ['_teksttv_date_start' => current_datetime()->modify('+1 day')->format('Y-m-d')],
                'wrong weekday' => ['_teksttv_days' => []],
                'suppressed images' => [], 'password' => [],
            ];
            $results = [];
            foreach ($cases as $name => $overrides) {
                foreach (array_merge($defaults, $overrides) as $key => $value) {
                    update_post_meta($post->ID, $key, $value);
                }
                wp_update_post(['ID' => $post->ID, 'post_password' => $name === 'password' ? 'secret' : '']);
                $filter = static fn() => '';
                if ($name === 'suppressed images') {
                    add_filter('teksttv_image_url', $filter);
                }
                \\TekstTV\\Blocks\\BuildContext::reset();
                $slides = \\TekstTV\\Blocks\\Loop\\ArticlesLoopBlock::build(['count' => 1]);
                $GLOBALS['post'] = get_post($post->ID);
                ob_start();
                \\TekstTV\\RssFeed::render_namespace();
                ob_clean();
                \\TekstTV\\RssFeed::render_item();
                $results[$name] = ['slides' => $slides, 'rss' => ob_get_clean()];
                remove_filter('teksttv_image_url', $filter);
            }
            wp_update_post(['ID' => $post->ID, 'post_password' => '']);
            echo wp_json_encode($results);
        `),
    );
    expect(results.eligible.slides).toHaveLength(3);
    expect(results.eligible.slides[0]).toMatchObject({ type: 'text', title: 'Gedeelde kop', body: '<p>Eerste</p>\n' });
    expect(results.eligible.slides[1].body).toBe('<p>Tweede</p>\n');
    expect(results.eligible.slides[2].type).toBe('image');
    expect(results.eligible.rss).toContain('<teksttv:title>Gedeelde kop</teksttv:title>');
    expect(results.eligible.rss).toContain('Eerste</p>\n\n<hr />\n<p>Tweede');
    expect(results.eligible.rss).toContain(results.eligible.slides[0].image.url);
    expect(results.eligible.rss).toContain(results.eligible.slides[2].url);
    for (const name of ['inactive', 'invalid date', 'impossible date', 'expired', 'future', 'wrong weekday']) {
        expect(results[name].slides, name).toEqual([]);
        expect(results[name].rss, name).toBe('');
    }
    expect(results['suppressed images'].slides).toHaveLength(2);
    expect(results['suppressed images'].slides[0].image).toBeUndefined();
    expect(results['suppressed images'].rss).not.toContain('<teksttv:image>');
    expect(results['suppressed images'].rss).not.toContain('<teksttv:extraImage>');
    expect(results.password.slides).toHaveLength(3);
    expect(results.password.rss).toBe('');
});
