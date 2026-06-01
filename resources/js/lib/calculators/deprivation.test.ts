import { describe, expect, it } from 'vitest'
import { computeDeprivationStats } from './deprivation'

// Course is 17 weeks. For 2 lectures/week:
//   totalHours = 34, maxUnexcused = floor(34*0.15) = 5, maxAbsence = floor(34*0.25) = 8
describe('computeDeprivationStats', () => {
  it('computes the baseline (no absences, 2 hours/week)', () => {
    const stats = computeDeprivationStats({
      lecturesPerWeek: 2,
      unexcusedCount: 0,
      excusedCount: 0
    })
    expect(stats.totalHours).toBe(34)
    expect(stats.lectureWeight).toBe(2.94) // round(100/34, 2)
    expect(stats.unexcusedLeft).toBe(5) // min(5-0, 8-0)
    expect(stats.absenceLeft).toBe(8) // 8-0
    expect(stats.currentAbsenceRate).toBe(0)
    expect(stats.isDeprived).toBe(false)
  })

  it('takes the stricter of the unexcused cap and the overall cap', () => {
    // 4 unexcused + 0 excused: byUnexc = 5-4 = 1, byTotal = 8-4 = 4 -> min = 1
    const stats = computeDeprivationStats({
      lecturesPerWeek: 2,
      unexcusedCount: 4,
      excusedCount: 0
    })
    expect(stats.unexcusedLeft).toBe(1)
    expect(stats.absenceLeft).toBe(4)
    expect(stats.isDeprived).toBe(false)
  })

  it('lets excused absences eat into the overall cap', () => {
    // 0 unexcused + 6 excused: byUnexc = 5-0 = 5, byTotal = 8-6 = 2 -> unexcusedLeft = 2
    const stats = computeDeprivationStats({
      lecturesPerWeek: 2,
      unexcusedCount: 0,
      excusedCount: 6
    })
    expect(stats.unexcusedLeft).toBe(2)
    expect(stats.absenceLeft).toBe(2)
    expect(stats.isDeprived).toBe(false)
  })

  it('flags deprivation when the unexcused cap is exceeded', () => {
    // 6 unexcused: byUnexc = 5-6 = -1 -> deprived
    const stats = computeDeprivationStats({
      lecturesPerWeek: 2,
      unexcusedCount: 6,
      excusedCount: 0
    })
    expect(stats.unexcusedLeft).toBe(-1)
    expect(stats.isDeprived).toBe(true)
  })

  it('flags deprivation when the overall absence cap is exceeded', () => {
    // 0 unexcused + 9 excused: byTotal = 8-9 = -1 -> absenceLeft negative -> deprived
    const stats = computeDeprivationStats({
      lecturesPerWeek: 2,
      unexcusedCount: 0,
      excusedCount: 9
    })
    expect(stats.absenceLeft).toBe(-1)
    expect(stats.isDeprived).toBe(true)
  })

  it('computes the current absence rate to two decimals', () => {
    // 4 unexcused + 4 excused = 8 of 34 -> 23.53%
    const stats = computeDeprivationStats({
      lecturesPerWeek: 2,
      unexcusedCount: 4,
      excusedCount: 4
    })
    expect(stats.currentAbsenceRate).toBe(23.53)
  })

  it('handles a single lecture per week (17 total hours)', () => {
    // totalHours = 17, maxUnexcused = floor(2.55) = 2, maxAbsence = floor(4.25) = 4
    const stats = computeDeprivationStats({
      lecturesPerWeek: 1,
      unexcusedCount: 0,
      excusedCount: 0
    })
    expect(stats.totalHours).toBe(17)
    expect(stats.lectureWeight).toBe(5.88) // round(100/17, 2)
    expect(stats.unexcusedLeft).toBe(2)
    expect(stats.absenceLeft).toBe(4)
  })
})
