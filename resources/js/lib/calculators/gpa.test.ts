import { describe, expect, it } from 'vitest'
import { computeGpaStats, gradeValues, type GpaCourse } from './gpa'

describe('computeGpaStats', () => {
  it('returns all-zero stats for an empty course list', () => {
    expect(computeGpaStats([])).toEqual({
      gpa: 0,
      approximateGpa: 0,
      totalCredits: 0,
      totalPoints: 0
    })
  })

  it('computes a simple weighted GPA (A+ over 3cr, B over 3cr -> 3.5)', () => {
    const courses: GpaCourse[] = [
      { credits: '3', grade: { value: 'A+' } },
      { credits: '3', grade: { value: 'B' } }
    ]
    expect(computeGpaStats(courses)).toEqual({
      gpa: 3.5,
      approximateGpa: 3.5,
      totalCredits: 6,
      totalPoints: 21 // 3*4 + 3*3
    })
  })

  it('rounds gpa to 5 decimals and approximateGpa to 2 decimals', () => {
    // 1cr A+ (4) + 2cr C (2) -> (4 + 4) / 3 = 2.6666...
    const courses: GpaCourse[] = [
      { credits: '1', grade: { value: 'A+' } },
      { credits: '2', grade: { value: 'C' } }
    ]
    const stats = computeGpaStats(courses)
    expect(stats.gpa).toBe(2.66667)
    expect(stats.approximateGpa).toBe(2.67)
    expect(stats.totalCredits).toBe(3)
    expect(stats.totalPoints).toBe(8)
  })

  it('ignores rows missing a grade or with zero/invalid credits', () => {
    const courses: GpaCourse[] = [
      { credits: '3', grade: { value: 'A+' } }, // counted
      { credits: '0', grade: { value: 'A+' } }, // skipped: credits not > 0
      { credits: '3' }, // skipped: no grade
      { credits: '3', grade: { value: 'Z' } } // skipped: unknown grade
    ]
    const stats = computeGpaStats(courses)
    expect(stats.totalCredits).toBe(3)
    expect(stats.totalPoints).toBe(12)
    expect(stats.gpa).toBe(4)
  })

  it('supports Arabic-Indic numerals in the credits field', () => {
    const courses: GpaCourse[] = [
      { credits: '٣', grade: { value: 'A+' } },
      { credits: '٣', grade: { value: 'B' } }
    ]
    expect(computeGpaStats(courses)).toEqual({
      gpa: 3.5,
      approximateGpa: 3.5,
      totalCredits: 6,
      totalPoints: 21
    })
  })

  it('maps each letter grade to its documented point value', () => {
    expect(gradeValues).toEqual({
      'A+': 4,
      A: 3.75,
      'B+': 3.5,
      B: 3,
      'C+': 2.5,
      C: 2,
      'D+': 1.5,
      D: 1,
      F: 0
    })
  })

  it('counts an F grade toward credits but contributes zero points', () => {
    const courses: GpaCourse[] = [
      { credits: '3', grade: { value: 'A+' } },
      { credits: '3', grade: { value: 'F' } }
    ]
    const stats = computeGpaStats(courses)
    expect(stats.totalCredits).toBe(6)
    expect(stats.totalPoints).toBe(12)
    expect(stats.gpa).toBe(2)
  })
})
