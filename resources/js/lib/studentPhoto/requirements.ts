/**
 * The single source of truth for Umm Al-Qura's university-card photo rules.
 *
 * Transcribed from the university's own instruction sheet (الخدمات الإلكترونية →
 * خدمات أكاديمية → البوابة الأكاديمية → شخصي → رفع الصورة الشخصية للبطاقة الجامعية).
 * Every check, preset and piece of copy in the tool derives from this file — the
 * numbers are never repeated anywhere else.
 */

/** Photo shape: 3 cm wide × 4 cm tall, i.e. height must exceed width. */
export const ASPECT_WIDTH = 3;
export const ASPECT_HEIGHT = 4;
export const ASPECT_RATIO = ASPECT_WIDTH / ASPECT_HEIGHT;

/** Accepted pixel envelope: width 120→360 px, height 160→480 px, kept proportional. */
export const MIN_OUTPUT_WIDTH = 120;
export const MAX_OUTPUT_WIDTH = 360;
export const MIN_OUTPUT_HEIGHT = 160;
export const MAX_OUTPUT_HEIGHT = 480;

/**
 * "300 KB" is ambiguous between 300,000 and 307,200 bytes, and the portal's own
 * validator is a black box. We target the stricter decimal reading so a file
 * that passes here passes either implementation.
 */
export const MAX_OUTPUT_BYTES = 300_000;

/** The portal accepts JPG only. */
export const OUTPUT_MIME = 'image/jpeg';
export const OUTPUT_EXTENSION = 'jpg';
export const OUTPUT_FILE_NAME = `student-card-photo.${OUTPUT_EXTENSION}`;

/** A photo older than six months is rejected. */
export const MAX_PHOTO_AGE_MONTHS = 6;

export interface OutputSizePreset {
    width: number;
    height: number;
    label: string;
}

/**
 * The size ladder, largest first. Every rung is exactly 3:4 and inside the
 * accepted envelope, so any of them is acceptable to the portal; the top rung
 * is the university's own worked example (العرض 360 / الطول 480).
 *
 * The student never picks from these. Asking someone to choose a pixel size to
 * satisfy a rule they have not read is a question the tool should answer
 * itself — see `chooseOutputSize`.
 */
export const OUTPUT_SIZE_PRESETS: OutputSizePreset[] = [
    { width: 360, height: 480, label: '360 × 480' },
    { width: 300, height: 400, label: '300 × 400' },
    { width: 240, height: 320, label: '240 × 320' },
    { width: 120, height: 160, label: '120 × 160' },
];

export const DEFAULT_OUTPUT_SIZE = OUTPUT_SIZE_PRESETS[0];

/**
 * The largest allowed size the cropped region can fill with real pixels.
 * Choosing by available width is what keeps the output from being stretched:
 * a 4000 px phone photo gets the full 360 × 480, and a small crop steps down
 * instead of inventing detail. A crop below the smallest rung still gets that
 * rung — it is the minimum the portal accepts — and the clarity check says so.
 */
export function chooseOutputSize(availableWidth: number): OutputSizePreset {
    return OUTPUT_SIZE_PRESETS.find((preset) => availableWidth >= preset.width) ?? OUTPUT_SIZE_PRESETS[OUTPUT_SIZE_PRESETS.length - 1];
}

/**
 * Rules no software can verify from pixels alone: the student confirms them.
 * Each one is worded as a statement the student either can or cannot tick.
 */
export interface ManualRequirement {
    id: string;
    label: string;
    detail: string;
}

export const MANUAL_REQUIREMENTS: ManualRequirement[] = [
    {
        id: 'recent',
        label: 'الصورة حديثة ولم يمضِ على تصويرها أكثر من ٦ أشهر',
        detail: 'صورة قديمة سبب شائع جدًا لرفض الطلب.',
    },
    {
        id: 'attire',
        label: 'الصورة بالزي الرسمي',
        detail: 'لا ملابس رياضية أو منزلية.',
    },
    {
        id: 'face',
        label: 'الوجه ظاهر كاملًا ومقابل للكاميرا بشكل مباشر',
        detail: 'بلا ميل للرأس، ولا شيء يغطي ملامح الوجه.',
    },
    {
        id: 'no-sunglasses',
        label: 'لا توجد نظارة شمسية في الصورة',
        detail: 'النظارة الطبية الشفافة مقبولة، والشمسية مرفوضة.',
    },
    {
        id: 'not-paper',
        label: 'الصورة ليست ملصقة على ورق ولا صورة مأخوذة لورقة أو بطاقة',
        detail: 'صوّر نفسك بالكاميرا، أو احفظ الملف الأصلي من الاستوديو.',
    },
    {
        id: 'unedited',
        label: 'الصورة أصلية غير مركَّبة ولا معدَّلة إلكترونيًا',
        detail: 'الفلاتر وتغيير الملامح وتركيب الخلفية كلها أسباب رفض.',
    },
];

/** Who is responsible for a rule: the tool fixes it, verifies it, or cannot know. */
export type RuleResponsibility = 'fixed' | 'verified' | 'student';

export interface RuleSummary {
    text: string;
    responsibility: RuleResponsibility;
}

export const RESPONSIBILITY_LABELS: Record<RuleResponsibility, string> = {
    fixed: 'نضبطه لك',
    verified: 'نتحقّق منه',
    student: 'عليك أنت',
};

/** The university's published list, in its own order, for the teaching panel. */
export const UNIVERSITY_RULES: RuleSummary[] = [
    { text: 'نوع الملف JPG', responsibility: 'fixed' },
    { text: 'حجم الملف لا يزيد عن 300 KB', responsibility: 'fixed' },
    {
        text: 'المقاس 3 سم عرضًا × 4 سم طولًا، والطول أكبر من العرض — بالبكسل: العرض من 120 إلى 360 والطول من 160 إلى 480 بنفس النسبة',
        responsibility: 'fixed',
    },
    { text: 'الصورة واضحة جدًا وبجودة مناسبة ضمن الحجم المسموح', responsibility: 'verified' },
    { text: 'الصورة ملوّنة', responsibility: 'verified' },
    { text: 'الصورة حديثة ولا يزيد عمرها عن ستة (6) أشهر', responsibility: 'student' },
    { text: 'الصورة بالزي الرسمي', responsibility: 'student' },
    { text: 'الوجه ظاهر كاملًا، أي يقابل صاحب الصورة الكاميرا بشكل مباشر', responsibility: 'student' },
    { text: 'الصورة غير ملصقة على أي أوراق أخرى', responsibility: 'student' },
    { text: 'الصور بالنظارات الشمسية غير مقبولة', responsibility: 'student' },
    { text: 'الصور المركّبة أو المعدّلة إلكترونيًا غير مقبولة', responsibility: 'student' },
];

/** The portal path the student follows after downloading the file. */
export const UPLOAD_STEPS: string[] = [
    'سجّل الدخول إلى موقع جامعة أم القرى',
    'الخدمات الإلكترونية',
    'خدمات أكاديمية',
    'البوابة الأكاديمية',
    'شخصي',
    'رفع الصورة الشخصية للبطاقة الجامعية',
];
