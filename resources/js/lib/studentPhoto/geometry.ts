/**
 * Crop geometry for the 3:4 card photo. Everything here is pure math on plain
 * numbers so the interactive cropper stays a thin layer over tested behaviour:
 * the component owns gestures, this file owns what a gesture is allowed to do.
 */

import { ASPECT_RATIO } from './requirements';

export interface Size {
    width: number;
    height: number;
}

export interface CropRect {
    x: number;
    y: number;
    width: number;
    height: number;
}

/**
 * The cropper's state. `zoom` is relative to the largest 3:4 rectangle that
 * fits the image (zoom 1 = as much of the photo as the shape allows), and the
 * centre is expressed in image pixels so it survives viewport resizes.
 */
export interface CropView {
    zoom: number;
    centerX: number;
    centerY: number;
}

/** Never let the user zoom into a region smaller than this, in source pixels. */
export const MIN_CROP_WIDTH = 48;

/** Faces sit above the middle of a portrait, so the default frame starts high. */
export const DEFAULT_VERTICAL_BIAS = 0.45;

/** A 2D affine transform in canvas `setTransform(a, b, c, d, e, f)` order. */
export interface AffineTransform {
    a: number;
    b: number;
    c: number;
    d: number;
    e: number;
    f: number;
}

/** EXIF orientations 5–8 exchange the axes. */
export function isTransposedOrientation(orientation: number): boolean {
    return orientation >= 5 && orientation <= 8;
}

/** The size an image occupies once its EXIF orientation has been applied. */
export function orientedSize(size: Size, orientation: number): Size {
    return isTransposedOrientation(orientation) ? { width: size.height, height: size.width } : { width: size.width, height: size.height };
}

/**
 * The transform that draws a `size` image into a canvas of `orientedSize`, so
 * the result is upright regardless of how the camera was held.
 */
export function orientationTransform(orientation: number, size: Size): AffineTransform {
    const { width, height } = size;

    switch (orientation) {
        case 2:
            return { a: -1, b: 0, c: 0, d: 1, e: width, f: 0 };
        case 3:
            return { a: -1, b: 0, c: 0, d: -1, e: width, f: height };
        case 4:
            return { a: 1, b: 0, c: 0, d: -1, e: 0, f: height };
        case 5:
            return { a: 0, b: 1, c: 1, d: 0, e: 0, f: 0 };
        case 6:
            return { a: 0, b: 1, c: -1, d: 0, e: height, f: 0 };
        case 7:
            return { a: 0, b: -1, c: -1, d: 0, e: height, f: width };
        case 8:
            return { a: 0, b: -1, c: 1, d: 0, e: 0, f: width };
        default:
            return { a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 };
    }
}

/** Quarter turns clockwise expressed as the equivalent EXIF orientation code. */
export function orientationForQuarterTurns(quarters: number): number {
    return [1, 6, 3, 8][((quarters % 4) + 4) % 4];
}

/** Widest 3:4 crop the image can contain — limited by width or by height. */
export function maxCropWidth(size: Size, ratio: number = ASPECT_RATIO): number {
    return Math.max(0, Math.min(size.width, size.height * ratio));
}

/** How far the user may zoom in before the crop region gets uselessly small. */
export function maxZoom(size: Size, ratio: number = ASPECT_RATIO): number {
    const widest = maxCropWidth(size, ratio);

    if (widest <= MIN_CROP_WIDTH) {
        return 1;
    }

    return widest / MIN_CROP_WIDTH;
}

/** The opening frame: fully zoomed out, centred horizontally, biased upward. */
export function defaultView(size: Size, ratio: number = ASPECT_RATIO): CropView {
    return clampView({ zoom: 1, centerX: size.width / 2, centerY: size.height * DEFAULT_VERTICAL_BIAS }, size, ratio);
}

/**
 * Force a view back into legality: zoom within range, and the crop rectangle
 * fully inside the image. Degenerate sizes collapse to a zero-area view rather
 * than producing NaN downstream.
 */
export function clampView(view: CropView, size: Size, ratio: number = ASPECT_RATIO): CropView {
    const widest = maxCropWidth(size, ratio);

    if (widest <= 0) {
        return { zoom: 1, centerX: 0, centerY: 0 };
    }

    const zoom = Math.min(Math.max(view.zoom, 1), maxZoom(size, ratio));
    const cropWidth = widest / zoom;
    const cropHeight = cropWidth / ratio;
    const halfWidth = cropWidth / 2;
    const halfHeight = cropHeight / 2;

    return {
        zoom,
        centerX: Math.min(Math.max(view.centerX, halfWidth), size.width - halfWidth),
        centerY: Math.min(Math.max(view.centerY, halfHeight), size.height - halfHeight),
    };
}

/** The crop rectangle, in image pixels, described by a view. */
export function cropFromView(view: CropView, size: Size, ratio: number = ASPECT_RATIO): CropRect {
    const clamped = clampView(view, size, ratio);
    const widest = maxCropWidth(size, ratio);
    const cropWidth = widest === 0 ? 0 : widest / clamped.zoom;
    const cropHeight = ratio === 0 ? 0 : cropWidth / ratio;

    return {
        x: clamped.centerX - cropWidth / 2,
        y: clamped.centerY - cropHeight / 2,
        width: cropWidth,
        height: cropHeight,
    };
}

/**
 * How much the crop must be stretched to fill the output. Above 1 means real
 * detail is being invented, which is what the clarity warning keys off.
 */
export function upscaleFactor(cropWidth: number, outputWidth: number): number {
    return cropWidth <= 0 ? Number.POSITIVE_INFINITY : outputWidth / cropWidth;
}

/** Pan by a screen-space delta, given how many screen pixels one image pixel spans. */
export function panView(view: CropView, deltaX: number, deltaY: number, scale: number, size: Size, ratio: number = ASPECT_RATIO): CropView {
    if (scale <= 0) {
        return clampView(view, size, ratio);
    }

    return clampView({ zoom: view.zoom, centerX: view.centerX - deltaX / scale, centerY: view.centerY - deltaY / scale }, size, ratio);
}

/** Multiply the zoom, keeping the frame legal. */
export function zoomView(view: CropView, factor: number, size: Size, ratio: number = ASPECT_RATIO): CropView {
    return clampView({ ...view, zoom: view.zoom * factor }, size, ratio);
}

/**
 * Re-anchor a view after quarter-turning the image, so rotating does not throw
 * away the framing the student already chose.
 */
export function rotateView(view: CropView, size: Size, quarters: number, ratio: number = ASPECT_RATIO): CropView {
    const turns = ((quarters % 4) + 4) % 4;
    let current = { ...view };
    let currentSize = { ...size };

    for (let turn = 0; turn < turns; turn += 1) {
        current = {
            zoom: current.zoom,
            centerX: currentSize.height - current.centerY,
            centerY: current.centerX,
        };
        currentSize = { width: currentSize.height, height: currentSize.width };
    }

    return clampView(current, currentSize, ratio);
}
