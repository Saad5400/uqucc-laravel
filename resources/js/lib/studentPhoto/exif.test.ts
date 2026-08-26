import { describe, expect, it } from 'vitest';
import { parseExifDateString, readExif, withExifOrientation } from './exif';

interface TiffEntry {
    tag: number;
    type: number;
    value: number | string;
}

/**
 * Build a minimal but valid EXIF-in-JPEG buffer: SOI, an APP1 segment holding a
 * TIFF header with IFD0 (and optionally an Exif sub-IFD), then SOS.
 */
function buildJpegWithExif(options: { littleEndian?: boolean; ifd0?: TiffEntry[]; subIfd?: TiffEntry[]; corruptMagic?: boolean }): Uint8Array {
    const littleEndian = options.littleEndian ?? true;
    const ifd0 = [...(options.ifd0 ?? [])];
    const subIfd = options.subIfd ?? [];

    // Values longer than 4 bytes live in a heap after every IFD.
    const heap: number[] = [];
    const tiff: number[] = [];

    const pushShort = (target: number[], value: number) => {
        target.push(littleEndian ? value & 0xff : (value >> 8) & 0xff, littleEndian ? (value >> 8) & 0xff : value & 0xff);
    };
    const pushLong = (target: number[], value: number) => {
        const octets = [value & 0xff, (value >> 8) & 0xff, (value >> 16) & 0xff, (value >>> 24) & 0xff];

        target.push(...(littleEndian ? octets : octets.reverse()));
    };

    tiff.push(...(littleEndian ? [0x49, 0x49] : [0x4d, 0x4d]));
    pushShort(tiff, options.corruptMagic ? 1234 : 42);
    pushLong(tiff, 8);

    const subIfdOffsetPlaceholders: number[] = [];

    const writeIfd = (entries: TiffEntry[], nextIfdOffset = 0): void => {
        pushShort(tiff, entries.length);

        for (const entry of entries) {
            pushShort(tiff, entry.tag);
            pushShort(tiff, entry.type);

            if (typeof entry.value === 'string') {
                const ascii = [...entry.value].map((character) => character.charCodeAt(0));

                ascii.push(0);
                pushLong(tiff, ascii.length);

                if (ascii.length <= 4) {
                    tiff.push(...ascii, ...new Array(4 - ascii.length).fill(0));
                } else {
                    // Heap offsets are patched once the IFD block is sized.
                    subIfdOffsetPlaceholders.push(tiff.length);
                    pushLong(tiff, heap.length);
                    heap.push(...ascii);
                }
            } else if (entry.type === 3) {
                pushLong(tiff, 1);
                pushShort(tiff, entry.value);
                tiff.push(0, 0);
            } else {
                pushLong(tiff, 1);
                pushLong(tiff, entry.value);
            }
        }

        pushLong(tiff, nextIfdOffset);
    };

    if (subIfd.length > 0) {
        // IFD0 gets an ExifIFDPointer whose target sits right after IFD0.
        const ifd0Size = 2 + (ifd0.length + 1) * 12 + 4;

        ifd0.push({ tag: 0x8769, type: 4, value: 8 + ifd0Size });
    }

    writeIfd(ifd0);

    if (subIfd.length > 0) {
        writeIfd(subIfd);
    }

    const heapStart = tiff.length;

    for (const placeholder of subIfdOffsetPlaceholders) {
        const current =
            (littleEndian
                ? tiff[placeholder] | (tiff[placeholder + 1] << 8) | (tiff[placeholder + 2] << 16) | (tiff[placeholder + 3] << 24)
                : tiff[placeholder + 3] | (tiff[placeholder + 2] << 8) | (tiff[placeholder + 1] << 16) | (tiff[placeholder] << 24)) >>> 0;
        const patched = [
            (heapStart + current) & 0xff,
            ((heapStart + current) >> 8) & 0xff,
            ((heapStart + current) >> 16) & 0xff,
            ((heapStart + current) >>> 24) & 0xff,
        ];
        const ordered = littleEndian ? patched : patched.reverse();

        for (let index = 0; index < 4; index += 1) {
            tiff[placeholder + index] = ordered[index];
        }
    }

    tiff.push(...heap);

    const app1Payload = [0x45, 0x78, 0x69, 0x66, 0x00, 0x00, ...tiff];
    const segmentLength = app1Payload.length + 2;

    return new Uint8Array([0xff, 0xd8, 0xff, 0xe1, (segmentLength >> 8) & 0xff, segmentLength & 0xff, ...app1Payload, 0xff, 0xda, 0x00, 0x02]);
}

describe('parseExifDateString', () => {
    it('parses the EXIF date format', () => {
        const date = parseExifDateString('2026:02:14 09:31:07');

        expect(date?.getFullYear()).toBe(2026);
        expect(date?.getMonth()).toBe(1);
        expect(date?.getDate()).toBe(14);
        expect(date?.getHours()).toBe(9);
    });

    it('accepts the ISO-ish separator some cameras write', () => {
        expect(parseExifDateString('2026:02:14T09:31:07')).not.toBeNull();
    });

    it('rejects placeholder and impossible dates', () => {
        expect(parseExifDateString('0000:00:00 00:00:00')).toBeNull();
        expect(parseExifDateString('2026:02:31 09:31:07')).toBeNull();
        expect(parseExifDateString('not a date')).toBeNull();
        expect(parseExifDateString('')).toBeNull();
    });
});

describe('readExif', () => {
    it('reads orientation from a little-endian JPEG', () => {
        const jpeg = buildJpegWithExif({ ifd0: [{ tag: 0x0112, type: 3, value: 6 }] });

        expect(readExif(jpeg).orientation).toBe(6);
    });

    it('reads orientation from a big-endian JPEG', () => {
        const jpeg = buildJpegWithExif({ littleEndian: false, ifd0: [{ tag: 0x0112, type: 3, value: 8 }] });

        expect(readExif(jpeg).orientation).toBe(8);
    });

    it('reads the capture date out of the Exif sub-IFD', () => {
        const jpeg = buildJpegWithExif({
            ifd0: [{ tag: 0x0112, type: 3, value: 1 }],
            subIfd: [{ tag: 0x9003, type: 2, value: '2026:02:14 09:31:07' }],
        });
        const exif = readExif(jpeg);

        expect(exif.orientation).toBe(1);
        expect(exif.takenAt?.getFullYear()).toBe(2026);
        expect(exif.takenAt?.getMonth()).toBe(1);
    });

    it('falls back to the IFD0 DateTime when no original date exists', () => {
        const jpeg = buildJpegWithExif({ ifd0: [{ tag: 0x0132, type: 2, value: '2025:12:01 12:00:00' }] });

        expect(readExif(jpeg).takenAt?.getFullYear()).toBe(2025);
    });

    it('prefers DateTimeOriginal over the digitised and file dates', () => {
        const jpeg = buildJpegWithExif({
            ifd0: [{ tag: 0x0132, type: 2, value: '2020:01:01 12:00:00' }],
            subIfd: [
                { tag: 0x9003, type: 2, value: '2026:02:14 09:31:07' },
                { tag: 0x9004, type: 2, value: '2023:05:05 09:31:07' },
            ],
        });

        expect(readExif(jpeg).takenAt?.getFullYear()).toBe(2026);
    });

    it('ignores an out-of-range orientation value', () => {
        const jpeg = buildJpegWithExif({ ifd0: [{ tag: 0x0112, type: 3, value: 17 }] });

        expect(readExif(jpeg).orientation).toBeUndefined();
    });

    it('returns nothing for a JPEG with no EXIF segment', () => {
        expect(readExif(new Uint8Array([0xff, 0xd8, 0xff, 0xda, 0x00, 0x02, 0x01, 0x02]))).toEqual({});
    });

    it('returns nothing for a corrupt TIFF header', () => {
        expect(readExif(buildJpegWithExif({ corruptMagic: true, ifd0: [{ tag: 0x0112, type: 3, value: 6 }] }))).toEqual({});
    });

    it('returns nothing for non-EXIF formats and truncated buffers', () => {
        expect(readExif(new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))).toEqual({});
        expect(readExif(new Uint8Array([0xff, 0xd8]))).toEqual({});
        expect(readExif(new Uint8Array())).toEqual({});
    });

    it('survives a truncated EXIF segment instead of throwing', () => {
        const jpeg = buildJpegWithExif({ ifd0: [{ tag: 0x0112, type: 3, value: 6 }] });

        expect(() => readExif(jpeg.slice(0, 14))).not.toThrow();
    });

    it('reads a bare TIFF buffer', () => {
        const jpeg = buildJpegWithExif({ ifd0: [{ tag: 0x0112, type: 3, value: 3 }] });
        const tiff = jpeg.slice(12, jpeg.length - 4);

        expect(readExif(tiff).orientation).toBe(3);
    });

    it('accepts an ArrayBuffer as well as a view', () => {
        const jpeg = buildJpegWithExif({ ifd0: [{ tag: 0x0112, type: 3, value: 6 }] });
        const copy = new Uint8Array(jpeg);

        expect(readExif(copy.buffer).orientation).toBe(6);
    });
});

describe('withExifOrientation', () => {
    /** A JPEG header/footer is enough: the reader only walks the segments. */
    const bareJpeg = new Uint8Array([0xff, 0xd8, 0xff, 0xda, 0x00, 0x02, 0x11, 0x22]);

    it('round-trips every orientation through the reader', () => {
        for (const orientation of [1, 2, 3, 4, 5, 6, 7, 8]) {
            expect(readExif(withExifOrientation(bareJpeg, orientation)).orientation).toBe(orientation);
        }
    });

    it('keeps the original image data after the inserted segment', () => {
        const tagged = withExifOrientation(bareJpeg, 6);

        expect(tagged.length).toBe(bareJpeg.length + 36);
        expect([...tagged.subarray(tagged.length - 6)]).toEqual([0xff, 0xda, 0x00, 0x02, 0x11, 0x22]);
    });

    it('leaves a non-JPEG buffer untouched', () => {
        const png = new Uint8Array([0x89, 0x50, 0x4e, 0x47]);

        expect(withExifOrientation(png, 6)).toBe(png);
        expect(withExifOrientation(new Uint8Array(), 6).length).toBe(0);
    });
});
