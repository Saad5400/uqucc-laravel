import { describe, expect, it } from 'vitest';
import {
    clampView,
    composeTransforms,
    cropFromView,
    defaultView,
    isTransposedOrientation,
    maxCropWidth,
    maxZoom,
    MIN_CROP_WIDTH,
    orientationForQuarterTurns,
    orientationTransform,
    orientedSize,
    panView,
    rotateView,
    scaleTransform,
    translateTransform,
    upscaleFactor,
    zoomView,
    type Size,
} from './geometry';

const landscape: Size = { width: 4000, height: 3000 };
const portrait: Size = { width: 3000, height: 4000 };

/** Apply a transform the way canvas would, to assert corner mapping. */
function mapPoint(transform: ReturnType<typeof orientationTransform>, x: number, y: number): [number, number] {
    return [transform.a * x + transform.c * y + transform.e, transform.b * x + transform.d * y + transform.f];
}

describe('orientation', () => {
    it('knows which orientations swap the axes', () => {
        expect([1, 2, 3, 4].map(isTransposedOrientation)).toEqual([false, false, false, false]);
        expect([5, 6, 7, 8].map(isTransposedOrientation)).toEqual([true, true, true, true]);
    });

    it('swaps the size only for transposed orientations', () => {
        expect(orientedSize(landscape, 1)).toEqual(landscape);
        expect(orientedSize(landscape, 3)).toEqual(landscape);
        expect(orientedSize(landscape, 6)).toEqual({ width: 3000, height: 4000 });
        expect(orientedSize(landscape, 8)).toEqual({ width: 3000, height: 4000 });
    });

    it('maps the source top-left corner where each orientation demands', () => {
        const size: Size = { width: 100, height: 50 };

        expect(mapPoint(orientationTransform(1, size), 0, 0)).toEqual([0, 0]);
        expect(mapPoint(orientationTransform(2, size), 0, 0)).toEqual([100, 0]);
        expect(mapPoint(orientationTransform(3, size), 0, 0)).toEqual([100, 50]);
        expect(mapPoint(orientationTransform(4, size), 0, 0)).toEqual([0, 50]);
        expect(mapPoint(orientationTransform(5, size), 0, 0)).toEqual([0, 0]);
        expect(mapPoint(orientationTransform(6, size), 0, 0)).toEqual([50, 0]);
        expect(mapPoint(orientationTransform(7, size), 0, 0)).toEqual([50, 100]);
        expect(mapPoint(orientationTransform(8, size), 0, 0)).toEqual([0, 100]);
    });

    it('keeps every corner inside the oriented canvas for all eight orientations', () => {
        const size: Size = { width: 100, height: 50 };

        for (const orientation of [1, 2, 3, 4, 5, 6, 7, 8]) {
            const transform = orientationTransform(orientation, size);
            const canvas = orientedSize(size, orientation);

            for (const [x, y] of [
                [0, 0],
                [size.width, 0],
                [0, size.height],
                [size.width, size.height],
            ]) {
                const [mappedX, mappedY] = mapPoint(transform, x, y);

                expect(mappedX).toBeGreaterThanOrEqual(0);
                expect(mappedY).toBeGreaterThanOrEqual(0);
                expect(mappedX).toBeLessThanOrEqual(canvas.width);
                expect(mappedY).toBeLessThanOrEqual(canvas.height);
            }
        }
    });

    it('falls back to identity for a nonsense orientation value', () => {
        expect(orientationTransform(99, landscape)).toEqual({ a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 });
    });

    it('expresses user quarter turns as orientation codes', () => {
        expect([0, 1, 2, 3].map(orientationForQuarterTurns)).toEqual([1, 6, 3, 8]);
        expect(orientationForQuarterTurns(4)).toBe(1);
        expect(orientationForQuarterTurns(-1)).toBe(8);
    });
});

describe('crop sizing', () => {
    it('limits the crop by width on a portrait and by height on a landscape', () => {
        expect(maxCropWidth(landscape)).toBe(2250);
        expect(maxCropWidth(portrait)).toBe(3000);
    });

    it('never returns a negative crop width', () => {
        expect(maxCropWidth({ width: 0, height: 0 })).toBe(0);
    });

    it('caps zoom where the crop would shrink past the minimum', () => {
        expect(maxZoom(portrait)).toBeCloseTo(3000 / MIN_CROP_WIDTH);
        expect(maxZoom({ width: 30, height: 40 })).toBe(1);
    });
});

describe('views', () => {
    it('opens fully zoomed out and biased above centre', () => {
        const view = defaultView(landscape);

        expect(view.zoom).toBe(1);
        expect(view.centerX).toBe(2000);
        expect(view.centerY).toBe(1500);
    });

    it('biases the frame upward when the image is taller than the card shape', () => {
        const tall: Size = { width: 3000, height: 6000 };
        const view = defaultView(tall);

        expect(view.centerY).toBe(6000 * 0.45);
        expect(cropFromView(view, tall).y).toBeGreaterThanOrEqual(0);
    });

    it('centres instead of biasing when the image is exactly the card shape', () => {
        expect(defaultView(portrait).centerY).toBe(2000);
    });

    it('clamps zoom into range', () => {
        expect(clampView({ zoom: 0.2, centerX: 0, centerY: 0 }, portrait).zoom).toBe(1);
        expect(clampView({ zoom: 9999, centerX: 0, centerY: 0 }, portrait).zoom).toBeCloseTo(maxZoom(portrait));
    });

    it('pulls the crop back inside the image on both axes', () => {
        const view = clampView({ zoom: 1, centerX: -500, centerY: 99999 }, landscape);
        const crop = cropFromView(view, landscape);

        expect(crop.x).toBeCloseTo(0);
        expect(crop.y + crop.height).toBeCloseTo(landscape.height);
    });

    it('produces a 3:4 crop at every zoom level', () => {
        for (const zoom of [1, 1.5, 3, 12]) {
            const crop = cropFromView({ zoom, centerX: 1500, centerY: 2000 }, portrait);

            expect(crop.width / crop.height).toBeCloseTo(0.75);
        }
    });

    it('survives a degenerate image size without producing NaN', () => {
        const crop = cropFromView({ zoom: 2, centerX: 10, centerY: 10 }, { width: 0, height: 0 });

        expect(crop).toEqual({ x: 0, y: 0, width: 0, height: 0 });
    });

    it('pans against the drag direction in image pixels', () => {
        const start = { zoom: 2, centerX: 1500, centerY: 2000 };
        const panned = panView(start, 100, 50, 0.5, portrait);

        expect(panned.centerX).toBe(1500 - 200);
        expect(panned.centerY).toBe(2000 - 100);
    });

    it('ignores a pan with a nonsense scale', () => {
        const start = { zoom: 2, centerX: 1500, centerY: 2000 };

        expect(panView(start, 100, 50, 0, portrait)).toEqual(clampView(start, portrait));
    });

    it('zooms multiplicatively within the legal range', () => {
        const zoomed = zoomView({ zoom: 2, centerX: 1500, centerY: 2000 }, 1.5, portrait);

        expect(zoomed.zoom).toBe(3);
        expect(zoomView(zoomed, 0.01, portrait).zoom).toBe(1);
    });

    it('carries the framing through a quarter turn instead of resetting it', () => {
        const view = { zoom: 2, centerX: 1200, centerY: 1000 };
        const rotated = rotateView(view, portrait, 1);

        expect(rotated.zoom).toBe(2);
        expect(rotated.centerX).toBe(4000 - 1000);
        expect(rotated.centerY).toBe(1200);
    });

    it('clamps a rotated frame that would fall outside the new bounds', () => {
        const rotated = rotateView({ zoom: 2, centerX: 600, centerY: 1000 }, portrait, 1);
        const crop = cropFromView(rotated, { width: 4000, height: 3000 });

        expect(crop.y).toBeGreaterThanOrEqual(0);
        expect(crop.y + crop.height).toBeLessThanOrEqual(3000);
    });

    it('returns to the original framing after four quarter turns', () => {
        const view = clampView({ zoom: 2, centerX: 600, centerY: 1000 }, portrait);
        const rotated = rotateView(view, portrait, 4);

        expect(rotated.centerX).toBeCloseTo(view.centerX);
        expect(rotated.centerY).toBeCloseTo(view.centerY);
    });
});

describe('upscaleFactor', () => {
    it('is at most 1 when the crop is larger than the output', () => {
        expect(upscaleFactor(1200, 360)).toBeCloseTo(0.3);
    });

    it('exceeds 1 when detail has to be invented', () => {
        expect(upscaleFactor(180, 360)).toBe(2);
    });

    it('is infinite for an empty crop', () => {
        expect(upscaleFactor(0, 360)).toBe(Number.POSITIVE_INFINITY);
    });
});

describe('transform composition', () => {
    const size: Size = { width: 100, height: 50 };

    it('fuses two quarter turns into a half turn', () => {
        const quarter = orientationTransform(6, size);
        const swapped: Size = { width: size.height, height: size.width };
        const fused = composeTransforms(quarter, orientationTransform(6, swapped));

        // Source (0,0) through two 90° turns lands at the far corner.
        expect(mapPoint(fused, 0, 0)).toEqual(mapPoint(orientationTransform(3, size), 0, 0));
        expect(mapPoint(fused, size.width, size.height)).toEqual(mapPoint(orientationTransform(3, size), size.width, size.height));
    });

    it('composes EXIF orientation with a user rotation for every combination', () => {
        for (const orientation of [1, 2, 3, 4, 5, 6, 7, 8]) {
            const first = orientationTransform(orientation, size);
            const upright = orientedSize(size, orientation);
            const code = orientationForQuarterTurns(1);
            const fused = composeTransforms(first, orientationTransform(code, upright));
            const finalSize = orientedSize(upright, code);

            for (const [x, y] of [
                [0, 0],
                [size.width, 0],
                [0, size.height],
                [size.width, size.height],
            ]) {
                const [mappedX, mappedY] = mapPoint(fused, x, y);

                expect(mappedX).toBeGreaterThanOrEqual(-1e-9);
                expect(mappedY).toBeGreaterThanOrEqual(-1e-9);
                expect(mappedX).toBeLessThanOrEqual(finalSize.width + 1e-9);
                expect(mappedY).toBeLessThanOrEqual(finalSize.height + 1e-9);
            }
        }
    });

    it('brings a crop corner to the origin', () => {
        const shifted = translateTransform(orientationTransform(1, size), -20, -8);

        expect(mapPoint(shifted, 20, 8)).toEqual([0, 0]);
    });

    it('scales the mapped output uniformly', () => {
        const scaled = scaleTransform(translateTransform(orientationTransform(1, size), -20, -8), 0.5);

        expect(mapPoint(scaled, 40, 28)).toEqual([10, 10]);
    });

    it('leaves a point untouched under an identity composition', () => {
        const identity = orientationTransform(1, size);

        expect(composeTransforms(identity, identity)).toEqual(identity);
    });
});
