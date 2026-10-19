import { reseedFixtures } from './reseed-fixtures';
import { expect, test } from './test';

interface Measurement {
    posts: number;
    caller: 'rss' | 'playout';
    cold: boolean;
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
    expect(measurements).toHaveLength(8);
    const find = (posts: number, caller: Measurement['caller'], cold: boolean) =>
        measurements.find((other) => other.posts === posts && other.caller === caller && other.cold === cold);
    for (const measurement of measurements) {
        const label = JSON.stringify(measurement);
        expect(measurement.queries, label).toBeLessThanOrEqual(8);
        // Query counts must not grow with the number of articles.
        expect(measurement.queries, label).toBeLessThanOrEqual(
            find(5, measurement.caller, measurement.cold)?.queries ?? Number.NaN,
        );
        expect(measurement.text_count).toBe(measurement.posts);
        expect(measurement.image_count).toBe(measurement.posts * 2);
        expect(measurement.sidebar_count).toBe(measurement.posts);
        expect(measurement.hash).toBe(find(measurement.posts, measurement.caller, !measurement.cold)?.hash);
    }
});
