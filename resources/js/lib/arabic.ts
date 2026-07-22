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
