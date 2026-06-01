// Deprivation (حرمان) calculator core logic.
// Extracted verbatim from DeprivationCalculatorPage.vue so it can be unit-tested
// and reused. Behavior must remain identical to the original inline copy.

export const DEPRIVATION_WEEKS = 17 // عدد أسابيع المقرر
export const MAX_UNEXCUSED_RATE = 0.15 // %15 حد الغياب بدون عذر
export const MAX_ABSENCE_RATE = 0.25 // %25 حد الغياب الكلي

export interface DeprivationInput {
  /** عدد الساعات في الأسبوع */
  lecturesPerWeek: number
  /** ساعات الغياب الحالية بدون عذر */
  unexcusedCount: number
  /** ساعات الغياب الحالية بعذر */
  excusedCount: number
}

export interface DeprivationStats {
  /** وزن كل ساعة كنسبة مئوية (دقة منزلتين) */
  lectureWeight: number
  /** إجمالي عدد الساعات في الفصل */
  totalHours: number
  /** الساعات المتبقية قبل تجاوز 15% غياب بدون عذر (قد تكون سالبة) */
  unexcusedLeft: number
  /** الساعات المتبقية قبل تجاوز 25% غياب كلي (قد تكون سالبة) */
  absenceLeft: number
  /** نسبة الغياب الحالية كنسبة مئوية (دقة منزلتين) */
  currentAbsenceRate: number
  /** هل الطالب محروم؟ */
  isDeprived: boolean
}

/**
 * Computes deprivation statistics from absence counts.
 * Pure function: identical output for identical input, no side effects.
 */
export const computeDeprivationStats = ({
  lecturesPerWeek,
  unexcusedCount,
  excusedCount
}: DeprivationInput): DeprivationStats => {
  const totalHours = DEPRIVATION_WEEKS * lecturesPerWeek

  const lectureWeight = Math.round(((1 * 100) / totalHours) * 100) / 100

  const total = unexcusedCount + excusedCount
  const maxUnexcHours = Math.floor(totalHours * MAX_UNEXCUSED_RATE)
  const maxAbsHours = Math.floor(totalHours * MAX_ABSENCE_RATE)

  // 1) by the unexcused-only cap
  const byUnexcRule = maxUnexcHours - unexcusedCount
  // 2) by the overall cap (subtract what you've already used)
  const byTotalRule = maxAbsHours - total

  // you can only take the stricter of the two
  const unexcusedLeft = Math.min(byUnexcRule, byTotalRule)

  const absenceLeft = maxAbsHours - total

  const currentAbsenceRate =
    totalHours > 0 ? Math.round((total / totalHours) * 100 * 100) / 100 : 0

  const isDeprived = unexcusedLeft < 0 || absenceLeft < 0

  return {
    lectureWeight,
    totalHours,
    unexcusedLeft,
    absenceLeft,
    currentAbsenceRate,
    isDeprived
  }
}
