// Convert Arabic-Indic digits and separators to a JS number.
// Extracted verbatim from the calculator pages so it can be unit-tested
// and reused. Behavior must remain identical to the original inline copies.
export const parseArabicNumber = (text = ''): number =>
  parseFloat(
    text
      .trim()
      .replace(/[٠-٩]/g, (digit) => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit) + '')
      .replace(/[٫،,]/g, '.')
  ) || 0
