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
    for (const measurement of measurements) {
        expect(measurement.queries, JSON.stringify(measurement)).toBeLessThanOrEqual(6);
        expect(measurement.text_count).toBe(measurement.posts);
        expect(measurement.image_count).toBe(measurement.posts * 2);
        expect(measurement.sidebar_count).toBe(measurement.posts);
        const counterpart = measurements.find(
            (other) =>
                other.posts === measurement.posts &&
                other.caller === measurement.caller &&
                other.cold !== measurement.cold,
        );
        expect(measurement.hash).toBe(counterpart?.hash);
    }
});
