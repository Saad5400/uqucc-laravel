import { describe, expect, it } from 'vitest';
import type { PhotoMetrics } from './analysis';
import { chooseOutputSize } from './requirements';
import { BLANK_CONTRAST_THRESHOLD, buildPhotoChecks, SHARPNESS_WARN_THRESHOLD, worstStatus, type CheckInput, type PhotoCheck } from './checks';

const healthyMetrics: PhotoMetrics = {
    meanSaturation: 0.3,
    colorfulRatio: 0.6,
    sharpness: 0.004,
    meanLuma: 0.55,
    darkRatio: 0.02,
    brightRatio: 0.05,
    contrast: 0.2,
};

const now = new Date(2026, 7, 26);

function input(overrides: Partial<CheckInput> = {}): CheckInput {
    return {
        outputWidth: 360,
        outputHeight: 480,
        outputBytes: 120_000,
        cropWidth: 1200,
        metrics: healthyMetrics,
        takenAt: null,
        now,
        ...overrides,
    };
}

function find(checks: PhotoCheck[], id: string): PhotoCheck | undefined {
    return checks.find((check) => check.id === id);
}

describe('buildPhotoChecks', () => {
    it('passes everything for a well-framed recent photo', () => {
        const checks = buildPhotoChecks(input({ takenAt: new Date(2026, 6, 1) }));

        expect(checks.every((check) => check.status === 'pass')).toBe(true);
        expect(worstStatus(checks)).toBe('pass');
    });

    it('always reports JPG as the output format', () => {
        expect(find(buildPhotoChecks(input()), 'format')?.status).toBe('pass');
    });

    it('fails dimensions outside the accepted envelope', () => {
        expect(find(buildPhotoChecks(input({ outputWidth: 90, outputHeight: 120 })), 'dimensions')?.status).toBe('fail');
        expect(find(buildPhotoChecks(input({ outputWidth: 480, outputHeight: 640 })), 'dimensions')?.status).toBe('fail');
    });

    it('fails a shape that is not 3:4 or is wider than it is tall', () => {
        expect(find(buildPhotoChecks(input({ outputWidth: 360, outputHeight: 360 })), 'dimensions')?.status).toBe('fail');
        expect(find(buildPhotoChecks(input({ outputWidth: 360, outputHeight: 240 })), 'dimensions')?.status).toBe('fail');
    });

    it('accepts every allowed proportional size', () => {
        for (const [width, height] of [
            [120, 160],
            [240, 320],
            [300, 400],
            [360, 480],
        ]) {
            expect(find(buildPhotoChecks(input({ outputWidth: width, outputHeight: height })), 'dimensions')?.status).toBe('pass');
        }
    });

    it('fails a file over the 300 KB ceiling and names the size', () => {
        const check = find(buildPhotoChecks(input({ outputBytes: 320_000 })), 'file-size');

        expect(check?.status).toBe('fail');
        expect(check?.detail).toContain('300 KB');
    });

    it('fails an empty render rather than calling it a small file', () => {
        expect(find(buildPhotoChecks(input({ outputBytes: 0 })), 'file-size')?.status).toBe('fail');
    });

    it('warns when the crop has to be upscaled to reach the output size', () => {
        const check = find(buildPhotoChecks(input({ cropWidth: 180 })), 'clarity');

        expect(check?.status).toBe('warn');
        expect(check?.detail).toContain('180');
    });

    it('does not warn about upscaling for a crop that merely rounds down', () => {
        expect(find(buildPhotoChecks(input({ cropWidth: 359 })), 'clarity')?.status).toBe('pass');
    });

    it('warns about a blurry photo even when the crop is large enough', () => {
        const metrics = { ...healthyMetrics, sharpness: SHARPNESS_WARN_THRESHOLD / 2 };

        expect(find(buildPhotoChecks(input({ metrics })), 'clarity')?.status).toBe('warn');
    });

    it('fails a greyscale photo', () => {
        const metrics = { ...healthyMetrics, meanSaturation: 0.01, colorfulRatio: 0.005 };
        const check = find(buildPhotoChecks(input({ metrics })), 'color');

        expect(check?.status).toBe('fail');
        expect(worstStatus(buildPhotoChecks(input({ metrics })))).toBe('fail');
    });

    it('does not call a muted but coloured photo greyscale', () => {
        const metrics = { ...healthyMetrics, meanSaturation: 0.04, colorfulRatio: 0.2 };

        expect(find(buildPhotoChecks(input({ metrics })), 'color')?.status).toBe('pass');
    });

    it('warns on a dark, a blown-out, and a blank frame', () => {
        const dark = { ...healthyMetrics, meanLuma: 0.1 };
        const blown = { ...healthyMetrics, brightRatio: 0.6 };
        const blank = { ...healthyMetrics, contrast: BLANK_CONTRAST_THRESHOLD / 2 };

        expect(find(buildPhotoChecks(input({ metrics: dark })), 'exposure')?.status).toBe('warn');
        expect(find(buildPhotoChecks(input({ metrics: blown })), 'exposure')?.status).toBe('warn');
        expect(find(buildPhotoChecks(input({ metrics: blank })), 'exposure')?.detail).toContain('فارغًا');
    });

    it('omits the pixel-based checks when no metrics could be measured', () => {
        const checks = buildPhotoChecks(input({ metrics: null }));

        expect(find(checks, 'exposure')).toBeUndefined();
        expect(find(checks, 'color')?.status).toBe('pass');
        expect(find(checks, 'clarity')?.status).toBe('pass');
    });

    it('passes a photo captured inside the six-month window', () => {
        const check = find(buildPhotoChecks(input({ takenAt: new Date(2026, 4, 1) })), 'age');

        expect(check?.status).toBe('pass');
    });

    it('fails a photo captured before the six-month cutoff', () => {
        const check = find(buildPhotoChecks(input({ takenAt: new Date(2025, 11, 31) })), 'age');

        expect(check?.status).toBe('fail');
    });

    it('treats the cutoff day itself as still recent', () => {
        expect(find(buildPhotoChecks(input({ takenAt: new Date(2026, 1, 26) })), 'age')?.status).toBe('pass');
    });

    it('omits the age check when the file carries no capture date', () => {
        expect(find(buildPhotoChecks(input()), 'age')).toBeUndefined();
    });

    it('ignores a capture date in the future instead of failing a good photo', () => {
        expect(find(buildPhotoChecks(input({ takenAt: new Date(2027, 0, 1) })), 'age')).toBeUndefined();
    });
});

describe('worstStatus', () => {
    it('ranks fail over warn over pass', () => {
        expect(worstStatus([])).toBe('pass');
        expect(worstStatus([{ id: 'a', label: '', status: 'pass', detail: '' }])).toBe('pass');
        expect(
            worstStatus([
                { id: 'a', label: '', status: 'pass', detail: '' },
                { id: 'b', label: '', status: 'warn', detail: '' },
            ]),
        ).toBe('warn');
        expect(
            worstStatus([
                { id: 'a', label: '', status: 'warn', detail: '' },
                { id: 'b', label: '', status: 'fail', detail: '' },
            ]),
        ).toBe('fail');
    });
});

describe('chooseOutputSize', () => {
    it('gives a large photo the full size the university allows', () => {
        expect(chooseOutputSize(4000)).toMatchObject({ width: 360, height: 480 });
        expect(chooseOutputSize(360)).toMatchObject({ width: 360, height: 480 });
    });

    it('steps down instead of stretching a smaller crop', () => {
        expect(chooseOutputSize(359)).toMatchObject({ width: 300, height: 400 });
        expect(chooseOutputSize(299)).toMatchObject({ width: 240, height: 320 });
        expect(chooseOutputSize(239)).toMatchObject({ width: 120, height: 160 });
    });

    it('falls back to the smallest accepted size for a tiny crop', () => {
        expect(chooseOutputSize(80)).toMatchObject({ width: 120, height: 160 });
        expect(chooseOutputSize(0)).toMatchObject({ width: 120, height: 160 });
    });

    it('only ever returns a size the dimension rule accepts', () => {
        for (const width of [10, 119, 120, 250, 359, 360, 5000]) {
            const size = chooseOutputSize(width);
            const checks = buildPhotoChecks(input({ outputWidth: size.width, outputHeight: size.height }));

            expect(checks.find((check) => check.id === 'dimensions')?.status).toBe('pass');
        }
    });
});
