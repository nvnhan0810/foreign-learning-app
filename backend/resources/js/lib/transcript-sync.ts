export type TranscriptSegment = {
    start: number;
    end: number;
    text: string;
};

/**
 * Returns the index of the segment that contains `currentTime`, or -1.
 */
export function findActiveSegmentIndex(
    segments: readonly TranscriptSegment[],
    currentTime: number,
): number {
    if (segments.length === 0 || !Number.isFinite(currentTime)) {
        return -1;
    }

    for (let index = 0; index < segments.length; index += 1) {
        const segment = segments[index];
        if (!segment) {
            continue;
        }

        if (currentTime >= segment.start && currentTime < segment.end) {
            return index;
        }
    }

    const last = segments[segments.length - 1];
    if (last && currentTime >= last.start) {
        return segments.length - 1;
    }

    return -1;
}

export function parseTranscriptSegments(raw: unknown): TranscriptSegment[] {
    if (!Array.isArray(raw)) {
        return [];
    }

    const segments: TranscriptSegment[] = [];

    for (const item of raw) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const record = item as Record<string, unknown>;
        const text = typeof record.text === 'string' ? record.text.trim() : '';
        const start = typeof record.start === 'number' ? record.start : Number(record.start);
        const end = typeof record.end === 'number' ? record.end : Number(record.end);

        if (text === '' || !Number.isFinite(start) || !Number.isFinite(end)) {
            continue;
        }

        segments.push({ start, end, text });
    }

    return segments;
}
