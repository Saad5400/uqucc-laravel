/**
 * Pixel measurements behind the automatic warnings. These are deliberately
 * simple, explainable statistics rather than a model: the tool only ever warns
 * on what it can defend ("this looks greyscale", "this looks blurry"), and
 * leaves judgement calls to the student's own checklist.
 *
 * Callers pass a small downscaled sample (see `ANALYSIS_SAMPLE_WIDTH`) so the
 * numbers mean the same thing whether the source was 8 MP or 0.3 MP.
 */

/** Analysis is done on a sample this wide, keeping thresholds resolution-independent. */
export const ANALYSIS_SAMPLE_WIDTH = 200;

export interface PixelSample {
    data: Uint8ClampedArray | Uint8Array | number[];
    width: number;
    height: number;
}

export interface PhotoMetrics {
    /** Mean HSV saturation over non-black pixels, 0–1. */
    meanSaturation: number;
    /** Share of pixels that are recognisably coloured, 0–1. */
    colorfulRatio: number;
    /** Variance of the Laplacian, normalised — higher is sharper. */
    sharpness: number;
    /** Mean luma, 0–1. */
    meanLuma: number;
    /** Share of near-black and near-white pixels, 0–1. */
    darkRatio: number;
    brightRatio: number;
    /** Standard deviation of luma, 0–1 — near zero means a blank frame. */
    contrast: number;
}

const EMPTY_METRICS: PhotoMetrics = {
    meanSaturation: 0,
    colorfulRatio: 0,
    sharpness: 0,
    meanLuma: 0,
    darkRatio: 0,
    brightRatio: 0,
    contrast: 0,
};

function luma(red: number, green: number, blue: number): number {
    return 0.299 * red + 0.587 * green + 0.114 * blue;
}

/** Measure a decoded RGBA sample. Returns zeroed metrics for an empty sample. */
export function measurePhoto(sample: PixelSample): PhotoMetrics {
    const { data, width, height } = sample;
    const pixelCount = width * height;

    if (pixelCount <= 0 || data.length < pixelCount * 4) {
        return { ...EMPTY_METRICS };
    }

    const grayscale = new Float64Array(pixelCount);

    let saturationSum = 0;
    let saturationSamples = 0;
    let colorfulCount = 0;
    let lumaSum = 0;
    let lumaSquaredSum = 0;
    let darkCount = 0;
    let brightCount = 0;

    for (let index = 0; index < pixelCount; index += 1) {
        const offset = index * 4;
        const red = data[offset];
        const green = data[offset + 1];
        const blue = data[offset + 2];

        const max = Math.max(red, green, blue);
        const min = Math.min(red, green, blue);

        // Saturation is meaningless in near-black pixels — sensor noise there
        // would otherwise make a greyscale photo look coloured.
        if (max >= 16) {
            const saturation = (max - min) / max;

            saturationSum += saturation;
            saturationSamples += 1;

            if (saturation >= 0.12) {
                colorfulCount += 1;
            }
        }

        const pixelLuma = luma(red, green, blue);

        grayscale[index] = pixelLuma;
        lumaSum += pixelLuma;
        lumaSquaredSum += pixelLuma * pixelLuma;

        if (pixelLuma <= 20) {
            darkCount += 1;
        }

        if (pixelLuma >= 247) {
            brightCount += 1;
        }
    }

    const meanLuma = lumaSum / pixelCount;
    const variance = Math.max(0, lumaSquaredSum / pixelCount - meanLuma * meanLuma);

    return {
        meanSaturation: saturationSamples === 0 ? 0 : saturationSum / saturationSamples,
        colorfulRatio: colorfulCount / pixelCount,
        sharpness: laplacianVariance(grayscale, width, height),
        meanLuma: meanLuma / 255,
        darkRatio: darkCount / pixelCount,
        brightRatio: brightCount / pixelCount,
        contrast: Math.sqrt(variance) / 255,
    };
}

/**
 * Variance of a 4-neighbour Laplacian over the interior pixels, scaled to a
 * 0–1-ish range. Flat or out-of-focus photos land near zero; a photo of a
 * printed photo (a common rejection cause) also scores low.
 */
function laplacianVariance(grayscale: Float64Array, width: number, height: number): number {
    if (width < 3 || height < 3) {
        return 0;
    }

    let sum = 0;
    let squaredSum = 0;
    let count = 0;

    for (let y = 1; y < height - 1; y += 1) {
        for (let x = 1; x < width - 1; x += 1) {
            const index = y * width + x;
            const value = 4 * grayscale[index] - grayscale[index - 1] - grayscale[index + 1] - grayscale[index - width] - grayscale[index + width];

            sum += value;
            squaredSum += value * value;
            count += 1;
        }
    }

    if (count === 0) {
        return 0;
    }

    const mean = sum / count;
    const variance = Math.max(0, squaredSum / count - mean * mean);

    return variance / (255 * 255);
}
