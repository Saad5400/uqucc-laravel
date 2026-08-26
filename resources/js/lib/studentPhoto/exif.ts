/**
 * A minimal, dependency-free EXIF reader for the two fields this tool needs:
 * orientation (so a sideways phone photo is straightened before cropping) and
 * the capture date (so a photo older than six months is caught before the
 * student uploads it).
 *
 * Every read is bounds-checked and the whole parse is failure-tolerant: a
 * truncated or malformed EXIF block yields an empty result rather than an
 * error, because a photo with unreadable metadata is still a usable photo.
 */

export interface ExifData {
    /** EXIF orientation 1–8, when present and valid. */
    orientation?: number;
    /** DateTimeOriginal, falling back to DateTimeDigitized then the IFD0 DateTime. */
    takenAt?: Date;
}

const TAG_ORIENTATION = 0x0112;
const TAG_DATE_TIME = 0x0132;
const TAG_EXIF_IFD_POINTER = 0x8769;
const TAG_DATE_TIME_ORIGINAL = 0x9003;
const TAG_DATE_TIME_DIGITIZED = 0x9004;

const TYPE_BYTE_SIZES: Record<number, number> = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };

/** "2026:02:14 09:31:07" → a local Date; anything else → null. */
export function parseExifDateString(value: string): Date | null {
    const match = /^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(value.trim());

    if (!match) {
        return null;
    }

    const [, year, month, day, hour, minute, second] = match.map(Number) as unknown as number[];
    const date = new Date(year, month - 1, day, hour, minute, second);

    const isConsistent = date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;

    return isConsistent ? date : null;
}

interface IfdEntryValues {
    orientation?: number;
    dateTime?: string;
    dateTimeOriginal?: string;
    dateTimeDigitized?: string;
    exifIfdOffset?: number;
}

function readAscii(view: DataView, offset: number, length: number): string {
    let out = '';

    for (let index = 0; index < length; index += 1) {
        const position = offset + index;

        if (position >= view.byteLength) {
            break;
        }

        const code = view.getUint8(position);

        if (code === 0) {
            break;
        }

        out += String.fromCharCode(code);
    }

    return out;
}

function readIfd(view: DataView, tiffStart: number, ifdOffset: number, littleEndian: boolean, into: IfdEntryValues): void {
    const entriesStart = tiffStart + ifdOffset;

    if (entriesStart + 2 > view.byteLength) {
        return;
    }

    const entryCount = view.getUint16(entriesStart, littleEndian);

    for (let index = 0; index < entryCount; index += 1) {
        const entry = entriesStart + 2 + index * 12;

        if (entry + 12 > view.byteLength) {
            return;
        }

        const tag = view.getUint16(entry, littleEndian);
        const type = view.getUint16(entry + 2, littleEndian);
        const count = view.getUint32(entry + 4, littleEndian);
        const componentSize = TYPE_BYTE_SIZES[type] ?? 0;

        if (componentSize === 0 || count > 0xffff) {
            continue;
        }

        const totalSize = componentSize * count;
        const valueOffset = totalSize <= 4 ? entry + 8 : tiffStart + view.getUint32(entry + 8, littleEndian);

        if (valueOffset < 0 || valueOffset >= view.byteLength) {
            continue;
        }

        switch (tag) {
            case TAG_ORIENTATION:
                if (type === 3) {
                    into.orientation = view.getUint16(valueOffset, littleEndian);
                }
                break;
            case TAG_DATE_TIME:
                into.dateTime = readAscii(view, valueOffset, totalSize);
                break;
            case TAG_DATE_TIME_ORIGINAL:
                into.dateTimeOriginal = readAscii(view, valueOffset, totalSize);
                break;
            case TAG_DATE_TIME_DIGITIZED:
                into.dateTimeDigitized = readAscii(view, valueOffset, totalSize);
                break;
            case TAG_EXIF_IFD_POINTER:
                if (type === 4) {
                    into.exifIfdOffset = view.getUint32(valueOffset, littleEndian);
                }
                break;
        }
    }
}

function readTiff(view: DataView, tiffStart: number): ExifData {
    if (tiffStart + 8 > view.byteLength) {
        return {};
    }

    const byteOrder = view.getUint16(tiffStart, false);

    if (byteOrder !== 0x4949 && byteOrder !== 0x4d4d) {
        return {};
    }

    const littleEndian = byteOrder === 0x4949;

    if (view.getUint16(tiffStart + 2, littleEndian) !== 42) {
        return {};
    }

    const values: IfdEntryValues = {};

    readIfd(view, tiffStart, view.getUint32(tiffStart + 4, littleEndian), littleEndian, values);

    if (values.exifIfdOffset !== undefined) {
        readIfd(view, tiffStart, values.exifIfdOffset, littleEndian, values);
    }

    const result: ExifData = {};

    if (values.orientation !== undefined && values.orientation >= 1 && values.orientation <= 8) {
        result.orientation = values.orientation;
    }

    const rawDate = values.dateTimeOriginal || values.dateTimeDigitized || values.dateTime;
    const parsed = rawDate ? parseExifDateString(rawDate) : null;

    if (parsed) {
        result.takenAt = parsed;
    }

    return result;
}

/** Locate the EXIF TIFF header inside a JPEG's APP1 segment, if there is one. */
function findJpegExifStart(view: DataView): number | null {
    let offset = 2;

    while (offset + 4 <= view.byteLength) {
        if (view.getUint8(offset) !== 0xff) {
            return null;
        }

        const marker = view.getUint8(offset + 1);

        // Standalone markers carry no length payload.
        if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd9)) {
            offset += 2;
            continue;
        }

        // Start of scan: image data begins, no metadata past this point.
        if (marker === 0xda) {
            return null;
        }

        const segmentLength = view.getUint16(offset + 2, false);

        if (segmentLength < 2) {
            return null;
        }

        if (marker === 0xe1 && readAscii(view, offset + 4, 4) === 'Exif') {
            return offset + 10;
        }

        offset += 2 + segmentLength;
    }

    return null;
}

/**
 * Read orientation and capture date from a JPEG (APP1) or raw TIFF buffer.
 * Formats without EXIF (PNG, WebP, BMP…) simply return an empty object.
 */
export function readExif(buffer: ArrayBuffer | Uint8Array): ExifData {
    try {
        const view = buffer instanceof Uint8Array ? new DataView(buffer.buffer, buffer.byteOffset, buffer.byteLength) : new DataView(buffer);

        if (view.byteLength < 8) {
            return {};
        }

        const leadingBytes = view.getUint16(0, false);

        if (leadingBytes === 0xffd8) {
            const exifStart = findJpegExifStart(view);

            return exifStart === null ? {} : readTiff(view, exifStart);
        }

        if (leadingBytes === 0x4949 || leadingBytes === 0x4d4d) {
            return readTiff(view, 0);
        }

        return {};
    } catch {
        return {};
    }
}

/**
 * A minimal EXIF block declaring nothing but an orientation: little-endian
 * TIFF header, one IFD entry, no sub-IFD. Byte 28 holds the orientation value.
 */
const MINIMAL_EXIF_SEGMENT: number[] = [
    0xff, 0xe1, 0x00, 0x22, 0x45, 0x78, 0x69, 0x66, 0x00, 0x00, 0x49, 0x49, 0x2a, 0x00, 0x08, 0x00, 0x00, 0x00, 0x01, 0x00, 0x12,
    0x01, 0x03, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
];

const ORIENTATION_VALUE_OFFSET = 28;

/**
 * Return a copy of a JPEG with an EXIF orientation tag spliced in after the SOI
 * marker. Used to build the probe image that tells us whether this browser
 * honours `imageOrientation: 'none'`; a buffer that is not a JPEG comes back
 * untouched.
 */
export function withExifOrientation(jpeg: Uint8Array, orientation: number): Uint8Array {
    if (jpeg.length < 2 || jpeg[0] !== 0xff || jpeg[1] !== 0xd8) {
        return jpeg;
    }

    const segment = [...MINIMAL_EXIF_SEGMENT];

    segment[ORIENTATION_VALUE_OFFSET] = orientation & 0xff;

    const out = new Uint8Array(jpeg.length + segment.length);

    out.set(jpeg.subarray(0, 2), 0);
    out.set(segment, 2);
    out.set(jpeg.subarray(2), 2 + segment.length);

    return out;
}
