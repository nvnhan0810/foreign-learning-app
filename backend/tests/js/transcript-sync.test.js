import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { findActiveSegmentIndex, parseTranscriptSegments } from '../../resources/js/lib/transcript-sync.ts';

describe('findActiveSegmentIndex', () => {
    const segments = [
        { start: 0, end: 3.5, text: 'a' },
        { start: 3.5, end: 7, text: 'b' },
        { start: 7, end: 12, text: 'c' },
    ];

    it('returns the matching segment', () => {
        assert.equal(findActiveSegmentIndex(segments, 0), 0);
        assert.equal(findActiveSegmentIndex(segments, 3.49), 0);
        assert.equal(findActiveSegmentIndex(segments, 3.5), 1);
        assert.equal(findActiveSegmentIndex(segments, 8), 2);
    });

    it('clamps past the last segment', () => {
        assert.equal(findActiveSegmentIndex(segments, 99), 2);
    });

    it('returns -1 before any segment', () => {
        assert.equal(findActiveSegmentIndex([{ start: 5, end: 6, text: 'x' }], 1), -1);
    });
});

describe('parseTranscriptSegments', () => {
    it('filters invalid rows', () => {
        const parsed = parseTranscriptSegments([
            { start: 1, end: 2, text: 'ok' },
            { start: 'x', end: 2, text: 'bad' },
            { start: 3, end: 4, text: '  ' },
        ]);

        assert.deepEqual(parsed, [{ start: 1, end: 2, text: 'ok' }]);
    });
});
