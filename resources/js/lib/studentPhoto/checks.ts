/**
 * Turns the rendered output plus the pixel metrics into the verdict list the
 * student reads. One function, one place: the UI renders whatever this returns,
 * so a rule can never disagree with itself across screens.
 */

import { formatFileSize, formatFullDate } from '@/lib/formatters';
import type { PhotoMetrics } from './analysis';
import { MAX_OUTPUT_BYTES, MAX_OUTPUT_HEIGHT, MAX_OUTPUT_WIDTH, MAX_PHOTO_AGE_MONTHS, MIN_OUTPUT_HEIGHT, MIN_OUTPUT_WIDTH } from './requirements';

export type CheckStatus = 'pass' | 'warn' | 'fail';

export interface PhotoCheck {
    id: string;
    label: string;
    status: CheckStatus;
    detail: string;
}

/** A photo that scores below this on Laplacian variance reads as soft or blurry. */
export const SHARPNESS_WARN_THRESHOLD = 0.0004;

/** Below these two together, the photo is effectively greyscale. */
export const GRAYSCALE_SATURATION_THRESHOLD = 0.05;
export const GRAYSCALE_COLORFUL_THRESHOLD = 0.03;

export const DARK_LUMA_THRESHOLD = 0.18;
export const DARK_PIXEL_RATIO_THRESHOLD = 0.5;
export const BLOWN_PIXEL_RATIO_THRESHOLD = 0.35;
export const BLANK_CONTRAST_THRESHOLD = 0.02;

export interface CheckInput {
    outputWidth: number;
    outputHeight: number;
    outputBytes: number;
    /** Width in source pixels of the region being cropped, for the upscale warning. */
    cropWidth: number;
    metrics: PhotoMetrics | null;
    /** EXIF capture date, when the original carried one. */
    takenAt: Date | null;
    now: Date;
}

/** Latin digits inside an RTL sentence read correctly when isolated. */
function ltr(value: string | number): string {
    return `⁦${value}⁩`;
}

function monthsAgo(date: Date, months: number): Date {
    const shifted = new Date(date.getTime());

    shifted.setMonth(shifted.getMonth() - months);

    return shifted;
}

function dimensionsCheck(width: number, height: number): PhotoCheck {
    const withinEnvelope = width >= MIN_OUTPUT_WIDTH && width <= MAX_OUTPUT_WIDTH && height >= MIN_OUTPUT_HEIGHT && height <= MAX_OUTPUT_HEIGHT;
    const isTallerThanWide = height > width;
    const isCardShape = Math.abs(width * 4 - height * 3) <= 1;
    const passes = withinEnvelope && isTallerThanWide && isCardShape;

    return {
        id: 'dimensions',
        label: 'المقاس ٣ × ٤ والطول أكبر من العرض',
        status: passes ? 'pass' : 'fail',
        detail: passes
            ? `${ltr(`${width} × ${height}`)} بكسل — داخل المدى المسموح (العرض ${ltr('120–360')}، الطول ${ltr('160–480')}).`
            : `${ltr(`${width} × ${height}`)} بكسل خارج المدى المسموح.`,
    };
}

function fileSizeCheck(bytes: number): PhotoCheck {
    const passes = bytes > 0 && bytes <= MAX_OUTPUT_BYTES;

    return {
        id: 'file-size',
        label: `حجم الملف لا يزيد عن ${ltr('300 KB')}`,
        status: passes ? 'pass' : 'fail',
        detail: passes
            ? `حجم الملف ${ltr(formatFileSize(bytes))} — بأعلى جودة ممكنة تحت الحد المسموح.`
            : `حجم الملف ${ltr(formatFileSize(bytes))} ويجب أن ينزل عن ${ltr('300 KB')}. اختر مقاسًا أصغر.`,
    };
}

function clarityCheck(cropWidth: number, outputWidth: number, metrics: PhotoMetrics | null): PhotoCheck {
    const isUpscaled = cropWidth > 0 && cropWidth < outputWidth * 0.98;
    const isSoft = metrics !== null && metrics.sharpness < SHARPNESS_WARN_THRESHOLD;

    if (isUpscaled) {
        return {
            id: 'clarity',
            label: 'الصورة واضحة وبجودة مناسبة',
            status: 'warn',
            detail: `الجزء المقتطع من صورتك عرضه ${ltr(Math.round(cropWidth))} بكسل فقط، وسيُكبَّر إلى ${ltr(outputWidth)} بكسل فيقل وضوحه. قلّل التكبير أو اختر مقاسًا أصغر أو استخدم صورة أعلى دقة.`,
        };
    }

    if (isSoft) {
        return {
            id: 'clarity',
            label: 'الصورة واضحة وبجودة مناسبة',
            status: 'warn',
            detail: 'الصورة تبدو غير واضحة أو مهتزة — والصور غير الواضحة مرفوضة. جرّب صورة أخرى بإضاءة أفضل وكاميرا ثابتة.',
        };
    }

    return {
        id: 'clarity',
        label: 'الصورة واضحة وبجودة مناسبة',
        status: 'pass',
        detail: 'درجة وضوح الصورة جيدة، ولم يحدث تكبير يفقدها التفاصيل.',
    };
}

function colorCheck(metrics: PhotoMetrics | null): PhotoCheck {
    const looksGrayscale =
        metrics !== null && metrics.meanSaturation < GRAYSCALE_SATURATION_THRESHOLD && metrics.colorfulRatio < GRAYSCALE_COLORFUL_THRESHOLD;

    return {
        id: 'color',
        label: 'الصورة ملوّنة',
        status: looksGrayscale ? 'fail' : 'pass',
        detail: looksGrayscale ? 'الصورة تبدو بالأبيض والأسود أو بفلتر يسحب الألوان، والصور غير الملوّنة مرفوضة.' : 'الصورة ملوّنة.',
    };
}

function exposureCheck(metrics: PhotoMetrics | null): PhotoCheck | null {
    if (metrics === null) {
        return null;
    }

    if (metrics.contrast < BLANK_CONTRAST_THRESHOLD) {
        return {
            id: 'exposure',
            label: 'الإضاءة مناسبة',
            status: 'warn',
            detail: 'الجزء المقتطع يبدو فارغًا أو بلون واحد — تأكد أن الوجه داخل الإطار.',
        };
    }

    if (metrics.meanLuma < DARK_LUMA_THRESHOLD || metrics.darkRatio > DARK_PIXEL_RATIO_THRESHOLD) {
        return {
            id: 'exposure',
            label: 'الإضاءة مناسبة',
            status: 'warn',
            detail: 'الصورة معتمة وقد تخفي ملامح الوجه. صوّر في مكان أفضل إضاءة.',
        };
    }

    if (metrics.brightRatio > BLOWN_PIXEL_RATIO_THRESHOLD) {
        return {
            id: 'exposure',
            label: 'الإضاءة مناسبة',
            status: 'warn',
            detail: 'أجزاء واسعة من الصورة شديدة البياض (إضاءة محروقة) وقد تخفي الملامح.',
        };
    }

    return {
        id: 'exposure',
        label: 'الإضاءة مناسبة',
        status: 'pass',
        detail: 'الإضاءة متوازنة.',
    };
}

function ageCheck(takenAt: Date | null, now: Date): PhotoCheck | null {
    if (takenAt === null) {
        return null;
    }

    const cutoff = monthsAgo(now, MAX_PHOTO_AGE_MONTHS);
    const isFuture = takenAt.getTime() > now.getTime() + 24 * 60 * 60 * 1000;

    if (isFuture) {
        return null;
    }

    const isRecent = takenAt.getTime() >= cutoff.getTime();
    const label = `عمر الصورة لا يزيد عن ${ltr(MAX_PHOTO_AGE_MONTHS)} أشهر`;
    const formatted = formatFullDate(takenAt);

    return {
        id: 'age',
        label,
        status: isRecent ? 'pass' : 'fail',
        detail: isRecent
            ? `بيانات الملف تقول إنها صُوّرت في ${formatted}.`
            : `بيانات الملف تقول إنها صُوّرت في ${formatted}، أي أقدم من ${ltr(MAX_PHOTO_AGE_MONTHS)} أشهر. استخدم صورة حديثة.`,
    };
}

/** Build the automatic verdict list, hardest requirements first. */
export function buildPhotoChecks(input: CheckInput): PhotoCheck[] {
    const checks: PhotoCheck[] = [
        {
            id: 'format',
            label: 'نوع الملف JPG',
            status: 'pass',
            detail: 'الملف الناتج يُحفظ دائمًا بصيغة JPG بامتداد ‎.jpg‎.',
        },
        dimensionsCheck(input.outputWidth, input.outputHeight),
        fileSizeCheck(input.outputBytes),
        clarityCheck(input.cropWidth, input.outputWidth, input.metrics),
        colorCheck(input.metrics),
    ];

    const exposure = exposureCheck(input.metrics);

    if (exposure) {
        checks.push(exposure);
    }

    const age = ageCheck(input.takenAt, input.now);

    if (age) {
        checks.push(age);
    }

    return checks;
}

/** The most severe status in a list — drives the summary banner. */
export function worstStatus(checks: PhotoCheck[]): CheckStatus {
    if (checks.some((check) => check.status === 'fail')) {
        return 'fail';
    }

    if (checks.some((check) => check.status === 'warn')) {
        return 'warn';
    }

    return 'pass';
}
