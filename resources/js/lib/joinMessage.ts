/**
 * The join request a student sends a supervisor, written for them and handed to
 * WhatsApp or Telegram as a draft.
 *
 * The point is not saving typing. A supervisor vetting a hundred requests an
 * evening gets the same four facts, in the same order, in every message — so
 * «من أي دفعة؟ وأي فرع؟» stops being a round trip, and a request that is
 * missing something is missing it visibly.
 *
 * Kept pure and in one file: the wording is edited here rather than in three
 * components, and it is tested without mounting anything.
 */

/** What the student told us in step 1, already in its display form. */
export interface StudentDetails {
    /** «دفعة ٤٨» */
    cohort: string;
    /** «شطر الطلاب» */
    section: string;
    /** «علوم الحاسب» */
    major: string;
    /** «الفرع الرئيسي — مكة المكرمة» */
    branch: string;
}

export interface JoinRequest extends StudentDetails {
    /** The list being asked for — «القروب العام» or «قروب علوم الحاسب». */
    group: string;
    /** Addressed by name, so it does not read as a broadcast. */
    supervisor: string;
}

/**
 * The one sentence the checklist in step 2 is about. Written as «مرفق» rather
 * than «سأرفق» because the draft becomes the photo's caption on both apps once
 * the student attaches it — which is what the hint under the buttons asks for.
 */
const ATTACHMENT = 'مرفق صورة القبول النهائي من البوابة الأكاديمية، ويظهر فيها اسمي والتخصص والفرع وأول ٣ أرقام من الرقم الجامعي.';

/**
 * Blank lines separate the greeting, the ask, the facts and the attachment, so
 * the four facts survive as a scannable block in a chat bubble.
 */
export function buildJoinMessage(request: JoinRequest): string {
    return [
        `السلام عليكم ${request.supervisor}`,
        '',
        `أرغب بالانضمام إلى «${request.group}».`,
        '',
        `الدفعة: ${request.cohort}`,
        `الشطر: ${request.section}`,
        `التخصص: ${request.major}`,
        `الفرع: ${request.branch}`,
        '',
        ATTACHMENT,
    ].join('\n');
}

/**
 * The same contact URL, carrying the message as a draft.
 *
 * Both `wa.me` and `t.me` read it from a `text` parameter. WhatsApp honours it
 * everywhere; Telegram's support varies by client, which is why the button that
 * opens Telegram also puts the message on the clipboard — see `handoff.ts`.
 *
 * Encoded with `encodeURIComponent` rather than `URLSearchParams`, which would
 * write spaces as `+` — WhatsApp shows those literally.
 */
export function withPrefilledMessage(url: string, message: string): string {
    return `${url}${url.includes('?') ? '&' : '?'}text=${encodeURIComponent(message)}`;
}
