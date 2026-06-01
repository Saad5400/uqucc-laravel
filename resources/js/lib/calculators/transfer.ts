import { parseArabicNumber } from './parseArabicNumber'

/**
 * Computes the internal-transfer composite score ("مركبة التحويل").
 *
 * weightedScore is a percentage (out of 100); cumulativeGpa is out of 4.
 * Multipliers are derived from the configured percentage split:
 *   weightedMultiplier = weightedPercentage / 100
 *   gpaMultiplier      = gpaPercentage / 4
 *
 * Returns null when either input is <= 0 (mirrors the UI which hides the
 * result card in that case). Pure function, no side effects.
 */
export const computeTransferScore = (
  weightedScore: string,
  cumulativeGpa: string,
  weightedPercentage = '50',
  gpaPercentage = '50'
): number | null => {
  const weighted = parseArabicNumber(weightedScore)
  const gpa = parseArabicNumber(cumulativeGpa)

  if (weighted <= 0 || gpa <= 0) {
    return null
  }

  const weightedMultiplier = parseArabicNumber(weightedPercentage) / 100
  const gpaMultiplier = parseArabicNumber(gpaPercentage) / 4

  return weighted * weightedMultiplier + gpa * gpaMultiplier
}
