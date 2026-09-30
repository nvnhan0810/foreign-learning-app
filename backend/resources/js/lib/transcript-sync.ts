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

export function segmentsToPlainText(segments: readonly TranscriptSegment[]): string {
    return segments
        .map((segment) => segment.text.trim())
        .filter((text) => text !== '')
        .join(' ');
}

/** Formats seconds as `m:ss` or `h:mm:ss` (floor). */
export function formatClock(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(total / 3600);
    const mins = Math.floor((total % 3600) / 60);
    const secs = total % 60;

    if (hours > 0) {
        return `${hours}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

/** Formats seconds for edit inputs as `m:ss.mmm`. */
export function formatClockPrecise(seconds: number): string {
    const safe = Number.isFinite(seconds) ? Math.max(0, seconds) : 0;
    const whole = Math.floor(safe);
    const millis = Math.round((safe - whole) * 1000);
    const mins = Math.floor(whole / 60);
    const secs = whole % 60;
    const milliPart = millis.toString().padStart(3, '0');

    return `${mins}:${secs.toString().padStart(2, '0')}.${milliPart}`;
}

/**
 * Parses `m:ss`, `m:ss.mmm`, `h:mm:ss`, or plain seconds into a number.
 */
export function parseClock(value: string): number | null {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    if (/^\d+(\.\d+)?$/.test(trimmed)) {
        const seconds = Number(trimmed);
        return Number.isFinite(seconds) && seconds >= 0 ? seconds : null;
    }

    const parts = trimmed.split(':');
    if (parts.length === 2 || parts.length === 3) {
        const secPart = parts[parts.length - 1] ?? '';
        const minPart = parts[parts.length - 2] ?? '0';
        const hourPart = parts.length === 3 ? (parts[0] ?? '0') : '0';

        if (!/^\d+$/.test(hourPart) || !/^\d+$/.test(minPart) || !/^\d+(\.\d+)?$/.test(secPart)) {
            return null;
        }

        const hours = Number(hourPart);
        const mins = Number(minPart);
        const secs = Number(secPart);

        if (!Number.isFinite(hours) || !Number.isFinite(mins) || !Number.isFinite(secs)) {
            return null;
        }

        if (mins >= 60 || secs >= 60) {
            return null;
        }

        return hours * 3600 + mins * 60 + secs;
    }

    return null;
}

export function createEmptySegment(afterSeconds = 0): TranscriptSegment {
    const start = Math.max(0, afterSeconds);
    return {
        start,
        end: start + 2,
        text: '',
    };
}
