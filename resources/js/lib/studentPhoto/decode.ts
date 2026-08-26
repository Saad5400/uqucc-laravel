/**
 * Getting an arbitrary file the student picked into something drawable, or
 * failing with an explanation they can act on.
 *
 * The browser is the only decoder available (the tool never uploads anything),
 * and it refuses plenty of real-world camera files — HEIC from an iPhone being
 * the big one. Every refusal here carries the reason and the way out.
 */

import { readExif, withExifOrientation } from './exif';
import { isRasterType, sniffFileType, type SniffedType } from './sniff';

export type DecodeFailure = 'empty' | 'too-large-file' | 'heif' | 'pdf' | 'svg' | 'tiff' | 'video' | 'unsupported' | 'decode-failed';

/** Refuse absurd inputs before spending memory on them. */
export const MAX_INPUT_BYTES = 60 * 1024 * 1024;

/** How much of the head to read when looking for EXIF (the segment can be large). */
const EXIF_SCAN_BYTES = 512 * 1024;

/** Fallback decode width when the full-size decode runs out of memory. */
const RESCUE_DECODE_WIDTH = 2048;

export class PhotoDecodeError extends Error {
    constructor(
        public readonly failure: DecodeFailure,
        message: string,
    ) {
        super(message);
        this.name = 'PhotoDecodeError';
    }
}

export interface DecodedPhoto {
    /** Anything `drawImage` accepts. */
    source: ImageBitmap | HTMLImageElement;
    /** Intrinsic size of `source`, before orientation is applied. */
    width: number;
    height: number;
    /** EXIF orientation still to be applied (1 when the decoder already did it). */
    orientation: number;
    takenAt: Date | null;
    fileType: SniffedType;
    fileName: string;
    fileBytes: number;
    /** Free the bitmap or object URL backing `source`. */
    release: () => void;
}

const REFUSAL_MESSAGES: Record<Exclude<DecodeFailure, 'decode-failed'>, string> = {
    empty: 'الملف فارغ أو تعذّر قراءته. جرّب اختياره مرة أخرى.',
    'too-large-file': 'حجم الملف أكبر من ٦٠ ميجابايت. اختر صورة أصغر أو صوّرها بجودة أقل من الكاميرا.',
    heif: 'هذه صورة بصيغة HEIC (الصيغة الافتراضية في الآيفون) ولا يستطيع المتصفح فتحها. من الآيفون: الإعدادات ← الكاميرا ← التنسيقات ← الأعلى توافقًا، ثم أعد التصوير — أو أرسل الصورة لنفسك في واتساب واحفظها ثم ارفعها هنا.',
    pdf: 'هذا ملف PDF لا صورة. افتحه واحفظ الصورة نفسها كملف صورة، ثم ارفعها هنا.',
    svg: 'هذا ملف رسم SVG ولا يصلح كصورة شخصية. ارفع صورة من الكاميرا.',
    tiff: 'صيغة TIFF لا يفتحها المتصفح. احفظ الصورة بصيغة JPG أو PNG ثم ارفعها هنا.',
    video: 'هذا ملف مقطع مرئي لا صورة. خُذ لقطة ثابتة منه وارفعها.',
    unsupported: 'تعذّر التعرف على نوع الملف. ارفع صورة بصيغة JPG أو PNG.',
};

function refuse(failure: Exclude<DecodeFailure, 'decode-failed'>): never {
    throw new PhotoDecodeError(failure, REFUSAL_MESSAGES[failure]);
}

async function readHead(file: File, bytes: number): Promise<Uint8Array> {
    return new Uint8Array(await file.slice(0, Math.min(bytes, file.size)).arrayBuffer());
}

/** Decode through an `<img>` element — the path for browsers without `createImageBitmap`. */
async function decodeViaImageElement(file: File): Promise<{ source: HTMLImageElement; release: () => void }> {
    const url = URL.createObjectURL(file);
    const image = new Image();

    image.decoding = 'sync';

    try {
        await new Promise<void>((resolve, reject) => {
            image.onload = () => resolve();
            image.onerror = () => reject(new Error('image element decode failed'));
            image.src = url;
        });
    } catch (error) {
        URL.revokeObjectURL(url);
        throw error;
    }

    return { source: image, release: () => URL.revokeObjectURL(url) };
}

/**
 * `imageOrientation: 'none'` is what we want for files whose EXIF we parsed
 * ourselves, but an unknown enum value throws on older engines — hence the
 * retry without options.
 */
async function createBitmap(file: File, imageOrientation: 'none' | 'from-image', resizeWidth?: number): Promise<ImageBitmap> {
    const options: ImageBitmapOptions = { imageOrientation };

    if (resizeWidth !== undefined) {
        options.resizeWidth = resizeWidth;
        options.resizeQuality = 'high';
    }

    try {
        return await createImageBitmap(file, options);
    } catch (error) {
        if (error instanceof TypeError) {
            return await createImageBitmap(file);
        }

        throw error;
    }
}

/**
 * Whether this browser honours `imageOrientation: 'none'`, resolved once per
 * page and remembered.
 *
 * This matters more than it sounds: if the browser silently straightens the
 * photo while we also apply the EXIF rotation ourselves, every sideways phone
 * photo comes out rotated twice. Rather than guess from user-agent strings, we
 * decode a two-pixel-wide JPEG tagged as rotated and look at which way round it
 * comes back.
 */
let orientationProbe: Promise<boolean> | null = null;

function honoursOrientationNone(): Promise<boolean> {
    orientationProbe ??= probeOrientationHandling();

    return orientationProbe;
}

function base64ToBytes(base64: string): Uint8Array {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

async function probeOrientationHandling(): Promise<boolean> {
    try {
        const canvas = document.createElement('canvas');

        canvas.width = 2;
        canvas.height = 1;

        const context = canvas.getContext('2d');

        if (!context) {
            return false;
        }

        context.fillStyle = '#808080';
        context.fillRect(0, 0, 2, 1);

        const dataUrl = canvas.toDataURL('image/jpeg', 0.5);
        const probe = withExifOrientation(base64ToBytes(dataUrl.slice(dataUrl.indexOf(',') + 1)), 6);
        const probeBlob = new Blob([probe.buffer as ArrayBuffer], { type: 'image/jpeg' });
        const bitmap = await createImageBitmap(probeBlob, { imageOrientation: 'none' });
        const untouched = bitmap.width === 2 && bitmap.height === 1;

        bitmap.close?.();

        return untouched;
    } catch {
        // Assume the browser straightens photos itself — the safe default,
        // since we then ask it explicitly to do so.
        return false;
    }
}

/**
 * Decode a picked file into a drawable source plus the metadata the rest of the
 * tool needs. Throws `PhotoDecodeError` with a student-readable message.
 */
export async function decodePhotoFile(file: File): Promise<DecodedPhoto> {
    if (file.size === 0) {
        refuse('empty');
    }

    if (file.size > MAX_INPUT_BYTES) {
        refuse('too-large-file');
    }

    const head = await readHead(file, 4096);
    const fileType = sniffFileType(head);

    if (fileType === 'pdf' || fileType === 'svg' || fileType === 'tiff' || fileType === 'video' || fileType === 'unknown') {
        refuse(fileType === 'unknown' ? 'unsupported' : fileType);
    }

    if (!isRasterType(fileType)) {
        refuse('unsupported');
    }

    // Only JPEG carries EXIF we can read here; for every other format the
    // browser's own orientation handling is all there is.
    const exif = fileType === 'jpeg' ? readExif(await readHead(file, EXIF_SCAN_BYTES)) : {};
    const decoded = await decodeSource(file, fileType);

    return describe(
        decoded.source,
        decoded.appliesOrientationItself ? 1 : (exif.orientation ?? 1),
        exif.takenAt ?? null,
        fileType,
        file,
        decoded.release,
    );
}

interface DecodedSource {
    source: ImageBitmap | HTMLImageElement;
    release: () => void;
    /** True when the decoder already straightened the image for us. */
    appliesOrientationItself: boolean;
}

/**
 * Try the decoders in order of fidelity: a full-size bitmap, a downscaled
 * bitmap (the rescue for out-of-memory on huge photos), then an `<img>`
 * element. HEIC exhausts all three on every browser but Safari, so it gets its
 * own message rather than the generic one.
 */
async function decodeSource(file: File, fileType: SniffedType): Promise<DecodedSource> {
    if (typeof createImageBitmap === 'function') {
        const appliesOrientationItself = !(await honoursOrientationNone());
        const orientationMode = appliesOrientationItself ? 'from-image' : 'none';

        for (const resizeWidth of [undefined, RESCUE_DECODE_WIDTH]) {
            try {
                const bitmap = await createBitmap(file, orientationMode, resizeWidth);

                return { source: bitmap, release: () => bitmap.close?.(), appliesOrientationItself };
            } catch {
                // Fall through to the next, cheaper decoder.
            }
        }
    }

    try {
        const fallback = await decodeViaImageElement(file);

        // An <img> element applies EXIF orientation itself.
        return { source: fallback.source, release: fallback.release, appliesOrientationItself: true };
    } catch {
        if (fileType === 'heif') {
            refuse('heif');
        }

        throw new PhotoDecodeError('decode-failed', 'تعذّر فتح هذه الصورة — قد يكون الملف تالفًا أو غير مكتمل. جرّب صورة أخرى.');
    }
}

function describe(
    source: ImageBitmap | HTMLImageElement,
    orientation: number,
    takenAt: Date | null,
    fileType: SniffedType,
    file: File,
    release: () => void,
): DecodedPhoto {
    const width = source instanceof HTMLImageElement ? source.naturalWidth : source.width;
    const height = source instanceof HTMLImageElement ? source.naturalHeight : source.height;

    if (width === 0 || height === 0) {
        release();

        throw new PhotoDecodeError('decode-failed', 'الصورة فُتحت بأبعاد صفرية — الملف تالف على الأغلب. جرّب صورة أخرى.');
    }

    return { source, width, height, orientation, takenAt, fileType, fileName: file.name, fileBytes: file.size, release };
}
