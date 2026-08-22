export interface ArabicNounForms {
    /** المفرد، مثل «نقطة» */
    singular: string;
    /** المثنى، مثل «نقطتان» */
    dual: string;
    /** الجمع، مثل «نقاط» */
    plural: string;
    /** صفة الواحد، «واحدة» للمؤنث (الافتراضي) أو «واحد» للمذكر */
    feminineOne?: string;
}

/**
 * Arabic count phrase with correct number agreement:
 * 1 → «نقطة واحدة»، 2 → «نقطتان»، 3–10 → «5 نقاط»، 0 و11+ → «15 نقطة».
 */
export function arabicCount(count: number, forms: ArabicNounForms): string {
    if (count === 1) {
        return `${forms.singular} ${forms.feminineOne ?? 'واحدة'}`;
    }

    if (count === 2) {
        return forms.dual;
    }

    if (count >= 3 && count <= 10) {
        return `${count} ${forms.plural}`;
    }

    return `${count} ${forms.singular}`;
}

/** «عضو واحد»، «عضوان»، «5 أعضاء»، «430 عضو». */
export function arabicMembers(count: number): string {
    return arabicCount(count, { singular: 'عضو', dual: 'عضوان', plural: 'أعضاء', feminineOne: 'واحد' });
}

/**
 * Totals across teams count memberships, not people — one person in three
 * teams is three عضويات. «عضوية واحدة»، «عضويتان»، «5 عضويات»، «430 عضوية».
 */
export function arabicMemberships(count: number): string {
    return arabicCount(count, { singular: 'عضوية', dual: 'عضويتان', plural: 'عضويات' });
}

/** «فريق واحد»، «فريقان»، «5 فرق»، «20 فريق». */
export function arabicTeams(count: number): string {
    return arabicCount(count, { singular: 'فريق', dual: 'فريقان', plural: 'فرق', feminineOne: 'واحد' });
}

/** «تصنيف واحد»، «تصنيفان»، «5 تصنيفات»، «12 تصنيف». */
export function arabicCategories(count: number): string {
    return arabicCount(count, { singular: 'تصنيف', dual: 'تصنيفان', plural: 'تصنيفات', feminineOne: 'واحد' });
}

/** «مشرف واحد»، «مشرفان»، «5 مشرفين»، «20 مشرف». */
export function arabicSupervisors(count: number): string {
    return arabicCount(count, { singular: 'مشرف', dual: 'مشرفان', plural: 'مشرفين', feminineOne: 'واحد' });
}

/**
 * Fold the spelling variants Arabic readers treat as the same letter, so a
 * search box matches «الامن السيبراني» against «الأمن السيبراني».
 *
 * Removes diacritics and tatweel, and unifies the alef forms, ta marbuta,
 * alef maksura and the hamza-carrying waw/ya.
 */
export function normalizeArabic(text: string): string {
    return (
        text
            .toLowerCase()
            // Tashkeel only. Written as escapes because these characters are
            // invisible or reorder under bidi in an editor, which makes a range
            // like this impossible to proofread as literals — and the range must
            // stop before U+0660, where the Arabic-Indic digits start («دفعة ٤٨»
            // has to keep its number).
            .replace(/[\u064B-\u065F\u0670]/g, '')
            // Alef forms → bare alef
            .replace(/[\u0622\u0623\u0625\u0627\u0671]/g, '\u0627')
            // Ta marbuta → ha
            .replace(/\u0629/g, '\u0647')
            // Alef maksura → ya
            .replace(/\u0649/g, '\u064A')
            // Hamza carriers → their bare letter
            .replace(/\u0624/g, '\u0648')
            .replace(/\u0626/g, '\u064A')
            // Tatweel (kashida)
            .replace(/\u0640/g, '')
            .replace(/\s+/g, ' ')
            .trim()
    );
}
