import { reseedFixtures } from './reseed-fixtures';
import { expect, test } from './test';

interface Measurement {
    posts: number;
    caller: 'rss' | 'playout';
    cold: boolean;
    primed: boolean;
    queries: number;
    hash: string;
    text_count: number;
    image_count: number;
    sidebar_count: number;
}

test.afterEach(reseedFixtures);

test('RSS and playout image queries stay bounded with cold and warm attachment caches', async ({
    runWordPressPHPFile,
}) => {
    const measurements: Measurement[] = JSON.parse(await runWordPressPHPFile('attachment-cache.php'));
    expect(measurements, 'measurements').toHaveLength(10);
    const find = (posts: number, caller: Measurement['caller'], cold: boolean, primed = true) => {
        const found = measurements.find(
            (other) =>
                other.posts === posts && other.caller === caller && other.cold === cold && other.primed === primed,
        );
        if (!found) {
            throw new Error(`No measurement for ${JSON.stringify({ posts, caller, cold, primed })}`);
        }
        return found;
    };
    for (const measurement of measurements) {
        const label = JSON.stringify(measurement);
        expect(measurement.text_count, `${label} text slides`).toBe(measurement.posts);
        expect(measurement.image_count, `${label} extra images`).toBe(measurement.posts * 2);
        expect(measurement.sidebar_count, `${label} sidebar images`).toBe(measurement.posts);
        // Priming and cache state never change the output.
        expect(measurement.hash, `${label} output matches the primed cold run`).toBe(
            find(measurement.posts, measurement.caller, true).hash,
        );
        if (!measurement.primed) {
            continue;
        }
        expect(measurement.queries, `${label} query bound`).toBeLessThanOrEqual(8);
        // Query counts must not grow with the number of articles.
        expect(measurement.queries, `${label} queries match 5 articles`).toBe(
            find(5, measurement.caller, measurement.cold).queries,
        );
    }
    // Positive control: without priming, the benchmark sees the per-image queries.
    expect(find(20, 'rss', true, false).queries, 'unprimed RSS queries exceed primed').toBeGreaterThan(
        find(20, 'rss', true).queries,
    );
});
