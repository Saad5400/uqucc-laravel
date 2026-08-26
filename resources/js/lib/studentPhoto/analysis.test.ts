import { describe, expect, it } from 'vitest';
import { measurePhoto, type PixelSample } from './analysis';

type PixelFactory = (x: number, y: number) => [number, number, number];

function sample(width: number, height: number, pixel: PixelFactory): PixelSample {
    const data = new Uint8ClampedArray(width * height * 4);

    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
            const offset = (y * width + x) * 4;
            const [red, green, blue] = pixel(x, y);

            data[offset] = red;
            data[offset + 1] = green;
            data[offset + 2] = blue;
            data[offset + 3] = 255;
        }
    }

    return { data, width, height };
}

const solidGray = sample(40, 40, () => [128, 128, 128]);
const solidRed = sample(40, 40, () => [220, 40, 40]);
const checkerboard = sample(40, 40, (x, y) => ((x + y) % 2 === 0 ? [240, 200, 160] : [40, 30, 20]));
const softGradient = sample(40, 40, (x) => [60 + x * 4, 50 + x * 4, 45 + x * 4]);
const darkFrame = sample(40, 40, () => [8, 9, 10]);
const blownFrame = sample(40, 40, () => [253, 253, 252]);

describe('measurePhoto', () => {
    it('reports a flat grey frame as unsaturated, flat and unsharp', () => {
        const metrics = measurePhoto(solidGray);

        expect(metrics.meanSaturation).toBe(0);
        expect(metrics.colorfulRatio).toBe(0);
        expect(metrics.contrast).toBeCloseTo(0, 5);
        expect(metrics.sharpness).toBe(0);
        expect(metrics.meanLuma).toBeCloseTo(128 / 255, 2);
    });

    it('reports saturated colour for a coloured frame', () => {
        const metrics = measurePhoto(solidRed);

        expect(metrics.meanSaturation).toBeGreaterThan(0.7);
        expect(metrics.colorfulRatio).toBe(1);
    });

    it('scores fine detail far above a smooth gradient', () => {
        const sharp = measurePhoto(checkerboard);
        const soft = measurePhoto(softGradient);

        expect(sharp.sharpness).toBeGreaterThan(soft.sharpness * 100);
        expect(soft.sharpness).toBeLessThan(0.0001);
    });

    it('flags a dark frame through luma and the dark-pixel share', () => {
        const metrics = measurePhoto(darkFrame);

        expect(metrics.meanLuma).toBeLessThan(0.1);
        expect(metrics.darkRatio).toBe(1);
        expect(metrics.brightRatio).toBe(0);
    });

    it('flags a blown-out frame through the bright-pixel share', () => {
        const metrics = measurePhoto(blownFrame);

        expect(metrics.brightRatio).toBe(1);
        expect(metrics.meanLuma).toBeGreaterThan(0.95);
    });

    it('ignores saturation noise in near-black pixels', () => {
        const nearBlackNoise = sample(20, 20, (x) => (x % 2 === 0 ? [4, 0, 0] : [0, 0, 6]));

        expect(measurePhoto(nearBlackNoise).meanSaturation).toBe(0);
    });

    it('measures contrast as the luma spread', () => {
        const halfBlackHalfWhite = sample(20, 20, (x) => (x < 10 ? [0, 0, 0] : [255, 255, 255]));

        expect(measurePhoto(halfBlackHalfWhite).contrast).toBeCloseTo(0.5, 1);
    });

    it('returns zeroed metrics for an empty or short sample', () => {
        expect(measurePhoto({ data: new Uint8ClampedArray(), width: 0, height: 0 }).sharpness).toBe(0);
        expect(measurePhoto({ data: new Uint8ClampedArray(4), width: 10, height: 10 }).meanLuma).toBe(0);
    });

    it('returns zero sharpness for samples too small to convolve', () => {
        expect(measurePhoto(sample(2, 2, () => [10, 200, 30])).sharpness).toBe(0);
    });

    it('accepts a plain number array as well as a typed array', () => {
        const plain = { data: [...solidRed.data], width: solidRed.width, height: solidRed.height };

        expect(measurePhoto(plain).colorfulRatio).toBe(1);
    });
});
