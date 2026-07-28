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
