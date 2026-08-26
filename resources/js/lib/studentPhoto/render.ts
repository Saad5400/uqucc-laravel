/**
 * Canvas work: straighten the decoded photo once, then crop-and-encode from
 * that upright copy on every adjustment.
 *
 * Nothing here edits the photo's content — no retouching, no background
 * replacement, no filters — because the university rejects composited or
 * electronically altered photos. The only operations are the mechanical ones:
 * rotate, crop, resize, and JPEG encoding.
 */

import { ANALYSIS_SAMPLE_WIDTH, measurePhoto, type PhotoMetrics } from './analysis';
import type { DecodedPhoto } from './decode';
import {
    composeTransforms,
    orientationForQuarterTurns,
    orientationTransform,
    orientedSize,
    scaleTransform,
    translateTransform,
    type AffineTransform,
    type CropRect,
    type Size,
} from './geometry';
import { ASPECT_RATIO, MAX_OUTPUT_BYTES, OUTPUT_MIME } from './requirements';

/**
 * The upright copy is capped at this on its longest side. The output is at most
 * 480 px tall, so this costs no visible quality and keeps an 8000 px phone
 * photo from making every pan and zoom sluggish.
 */
export const MAX_WORKING_SIDE = 2400;

/**
 * Start at maximum quality and only ever come down: at the sizes the university
 * allows, the byte ceiling almost never binds, so nothing is thrown away unless
 * the file genuinely exceeds 300 KB.
 */
const QUALITY_CEILING = 1;
const QUALITY_FLOOR = 0.2;
const QUALITY_PROBES = 8;

export interface WorkingImage {
    /** The upright, downscaled copy the interactive canvas draws. */
    canvas: HTMLCanvasElement;
    width: number;
    height: number;
    /** Working pixels per upright source pixel — the bridge back to full resolution. */
    scale: number;
    /** Kept so the final render can go back to the untouched source pixels. */
    photo: DecodedPhoto;
    quarterTurns: number;
}

/**
 * Ceiling on the full-resolution crop held in memory before downscaling. A
 * 24 MP crop is already far more detail than a 480 px output can use, and going
 * higher risks a canvas allocation failure on a phone.
 */
export const MAX_FULL_CROP_PIXELS = 24_000_000;

export interface RenderedPhoto {
    blob: Blob;
    width: number;
    height: number;
    bytes: number;
    /** The JPEG quality that fit under the size ceiling, for the diagnostics line. */
    quality: number;
}

function createCanvas(width: number, height: number): HTMLCanvasElement {
    const canvas = document.createElement('canvas');

    canvas.width = Math.max(1, Math.round(width));
    canvas.height = Math.max(1, Math.round(height));

    return canvas;
}

function context2d(canvas: HTMLCanvasElement): CanvasRenderingContext2D {
    const context = canvas.getContext('2d', { alpha: false });

    if (!context) {
        throw new Error('canvas 2d context unavailable');
    }

    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';

    return context;
}

/**
 * Draw the decoded photo upright — EXIF orientation and the student's quarter
 * turns applied, downscaled to the working cap — so all later cropping is a
 * plain sub-rectangle of a normal top-left-origin image.
 */
export function buildWorkingImage(decoded: DecodedPhoto, quarterTurns = 0): WorkingImage {
    const intrinsic: Size = { width: decoded.width, height: decoded.height };
    const upright = orientedSize(intrinsic, decoded.orientation);
    const scale = Math.min(1, MAX_WORKING_SIDE / Math.max(upright.width, upright.height));

    const straightened = createCanvas(upright.width * scale, upright.height * scale);
    const context = context2d(straightened);
    const transform = orientationTransform(decoded.orientation, intrinsic);

    context.setTransform(
        transform.a * scale,
        transform.b * scale,
        transform.c * scale,
        transform.d * scale,
        transform.e * scale,
        transform.f * scale,
    );
    context.drawImage(decoded.source, 0, 0);
    context.setTransform(1, 0, 0, 1, 0, 0);

    const turns = ((quarterTurns % 4) + 4) % 4;

    if (turns === 0) {
        return {
            canvas: straightened,
            width: straightened.width,
            height: straightened.height,
            scale,
            photo: decoded,
            quarterTurns: 0,
        };
    }

    // Quarter turns are axis-aligned, so this second pass resamples nothing.
    const code = orientationForQuarterTurns(turns);
    const source: Size = { width: straightened.width, height: straightened.height };
    const rotatedSize = orientedSize(source, code);
    const rotated = createCanvas(rotatedSize.width, rotatedSize.height);
    const rotatedContext = context2d(rotated);
    const rotation = orientationTransform(code, source);

    rotatedContext.setTransform(rotation.a, rotation.b, rotation.c, rotation.d, rotation.e, rotation.f);
    rotatedContext.drawImage(straightened, 0, 0);
    rotatedContext.setTransform(1, 0, 0, 1, 0, 0);

    return { canvas: rotated, width: rotated.width, height: rotated.height, scale, photo: decoded, quarterTurns: turns };
}

/**
 * The transform that maps raw decoded pixels to the upright, rotated space the
 * crop rectangle is expressed in — EXIF orientation and the student's quarter
 * turns fused into one matrix, so the final render resamples exactly once.
 */
function uprightTransform(photo: DecodedPhoto, quarterTurns: number): { transform: AffineTransform; size: Size } {
    const intrinsic: Size = { width: photo.width, height: photo.height };
    const orientation = orientationTransform(photo.orientation, intrinsic);
    const upright = orientedSize(intrinsic, photo.orientation);
    const turns = ((quarterTurns % 4) + 4) % 4;

    if (turns === 0) {
        return { transform: orientation, size: upright };
    }

    const code = orientationForQuarterTurns(turns);

    return {
        transform: composeTransforms(orientation, orientationTransform(code, upright)),
        size: orientedSize(upright, code),
    };
}

/**
 * Cut the crop out of the *original* decoded pixels rather than out of the
 * downscaled working copy. Nothing is resampled here beyond the memory guard:
 * this is the step that decides how much real detail the output can carry.
 */
function extractCropAtSourceResolution(working: WorkingImage, cropInWorkingPixels: CropRect): HTMLCanvasElement {
    const { transform, size } = uprightTransform(working.photo, working.quarterTurns);
    const inverseScale = working.scale > 0 ? 1 / working.scale : 1;

    const crop = clampCropToSize(size, {
        x: cropInWorkingPixels.x * inverseScale,
        y: cropInWorkingPixels.y * inverseScale,
        width: cropInWorkingPixels.width * inverseScale,
        height: cropInWorkingPixels.height * inverseScale,
    });

    const pixels = crop.width * crop.height;
    const guard = pixels > MAX_FULL_CROP_PIXELS ? Math.sqrt(MAX_FULL_CROP_PIXELS / pixels) : 1;

    const canvas = createCanvas(crop.width * guard, crop.height * guard);
    const context = context2d(canvas);
    const placed = scaleTransform(translateTransform(transform, -crop.x, -crop.y), guard);

    context.setTransform(placed.a, placed.b, placed.c, placed.d, placed.e, placed.f);
    context.drawImage(working.photo.source, 0, 0);
    context.setTransform(1, 0, 0, 1, 0, 0);

    return canvas;
}

/** Keep a crop rectangle inside an image of `size`, whatever the caller believed. */
function clampCropToSize(size: Size, crop: CropRect): CropRect {
    const width = Math.min(Math.max(crop.width, 1), size.width);
    const height = Math.min(Math.max(crop.height, 1), size.height);

    return {
        x: Math.min(Math.max(crop.x, 0), size.width - width),
        y: Math.min(Math.max(crop.y, 0), size.height - height),
        width,
        height,
    };
}

function clampCropToImage(working: WorkingImage, crop: CropRect): CropRect {
    return clampCropToSize({ width: working.width, height: working.height }, crop);
}

/**
 * Measure the cropped region at a fixed sample size, so the sharpness and
 * colour thresholds mean the same thing for every source resolution. Returns
 * null when the browser refuses to read the pixels back.
 */
export function measureCrop(working: WorkingImage, crop: CropRect): PhotoMetrics | null {
    const region = clampCropToImage(working, crop);

    try {
        const sample = createCanvas(ANALYSIS_SAMPLE_WIDTH, ANALYSIS_SAMPLE_WIDTH / ASPECT_RATIO);
        const context = context2d(sample);

        context.drawImage(working.canvas, region.x, region.y, region.width, region.height, 0, 0, sample.width, sample.height);

        const { data, width, height } = context.getImageData(0, 0, sample.width, sample.height);

        return measurePhoto({ data, width, height });
    } catch {
        return null;
    }
}

function dataUrlToBlob(dataUrl: string): Blob {
    const payload = dataUrl.slice(dataUrl.indexOf(',') + 1);
    const binary = atob(payload);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return new Blob([bytes], { type: OUTPUT_MIME });
}

/** Encode a canvas as JPEG, falling back to a data URL where `toBlob` fails. */
async function encodeJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
    if (typeof canvas.toBlob === 'function') {
        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob(resolve, OUTPUT_MIME, quality);
        });

        if (blob) {
            return blob;
        }
    }

    return dataUrlToBlob(canvas.toDataURL(OUTPUT_MIME, quality));
}

/**
 * Highest JPEG quality that still fits under the byte ceiling. At the sizes the
 * university allows, the ceiling almost never binds — but a busy, noisy photo
 * at 360 × 480 can reach it, and silently shipping an oversized file would be
 * worse than a slightly softer one.
 */
async function encodeUnderLimit(canvas: HTMLCanvasElement, maxBytes: number): Promise<{ blob: Blob; quality: number }> {
    const best = await encodeJpeg(canvas, QUALITY_CEILING);

    if (best.size <= maxBytes) {
        return { blob: best, quality: QUALITY_CEILING };
    }

    let low = QUALITY_FLOOR;
    let high = QUALITY_CEILING;
    let fitting: { blob: Blob; quality: number } | null = null;

    for (let probe = 0; probe < QUALITY_PROBES; probe += 1) {
        const quality = (low + high) / 2;
        const candidate = await encodeJpeg(canvas, quality);

        if (candidate.size <= maxBytes) {
            fitting = { blob: candidate, quality };
            low = quality;
        } else {
            high = quality;
        }
    }

    // Nothing fit: hand back the smallest attempt so the size check can fail
    // out loud and point the student at a smaller output size.
    return fitting ?? { blob: await encodeJpeg(canvas, QUALITY_FLOOR), quality: QUALITY_FLOOR };
}

/**
 * Render the chosen crop at the chosen output size as a JPEG under the byte
 * ceiling.
 *
 * The crop is taken from the original decoded pixels and resampled exactly
 * once. Going through the downscaled working copy instead would resample twice
 * — which is what made earlier output look softer than the photo the student
 * started with. Measured against an exact area-average reference, one pass from
 * full resolution beats both the two-step path and a halving ladder.
 *
 * The canvas is painted white first: JPEG has no alpha, and an unflattened
 * transparent PNG would otherwise come out with a black background.
 */
export async function renderCroppedJpeg(
    working: WorkingImage,
    crop: CropRect,
    target: Size,
    maxBytes: number = MAX_OUTPUT_BYTES,
): Promise<RenderedPhoto> {
    const region = clampCropToImage(working, crop);
    const fullResolutionCrop = extractCropAtSourceResolution(working, region);

    const canvas = createCanvas(target.width, target.height);
    const context = context2d(canvas);

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    // One high-quality resample, straight from the untouched source pixels.
    context.drawImage(fullResolutionCrop, 0, 0, fullResolutionCrop.width, fullResolutionCrop.height, 0, 0, canvas.width, canvas.height);

    const { blob, quality } = await encodeUnderLimit(canvas, maxBytes);

    return { blob, width: canvas.width, height: canvas.height, bytes: blob.size, quality };
}
