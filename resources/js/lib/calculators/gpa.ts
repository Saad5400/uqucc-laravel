import { parseArabicNumber } from './parseArabicNumber'

export interface GpaCourse {
  credits: string
  grade?: {
    value: string
    label?: string
  }
}

export interface GpaStats {
  gpa: number
  approximateGpa: number
  totalCredits: number
  totalPoints: number
}

export const gradeValues: Record<string, number> = {
  'A+': 4,
  A: 3.75,
  'B+': 3.5,
  B: 3,
  'C+': 2.5,
  C: 2,
  'D+': 1.5,
  D: 1,
  F: 0
}

/**
 * Computes GPA statistics from a list of courses.
 *
 * Only rows that have BOTH a credit value > 0 and a valid grade are counted.
 * - gpa: exact average rounded to 5 decimals
 * - approximateGpa: average rounded to 2 decimals
 * - totalCredits / totalPoints: sums over counted rows
 *
 * Pure function: identical output for identical input, no side effects.
 */
export const computeGpaStats = (courses: GpaCourse[]): GpaStats => {
  const { creditsSum, pointsSum } = courses.reduce(
    (acc, { credits, grade }) => {
      const creditValue = parseArabicNumber(credits)

      if (creditValue > 0 && grade?.value && gradeValues[grade.value] !== undefined) {
        acc.creditsSum += creditValue
        acc.pointsSum += creditValue * gradeValues[grade.value]
      }

      return acc
    },
    { creditsSum: 0, pointsSum: 0 }
  )

  const average = creditsSum ? pointsSum / creditsSum : 0
  return {
    gpa: +average.toFixed(5),
    approximateGpa: +average.toFixed(2),
    totalCredits: creditsSum,
    totalPoints: pointsSum
  }
}
