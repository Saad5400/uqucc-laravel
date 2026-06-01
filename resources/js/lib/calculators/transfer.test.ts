import { describe, expect, it } from 'vitest'
import { computeTransferScore } from './transfer'

describe('computeTransferScore', () => {
  it('computes the default 50/50 composite score', () => {
    // weighted 80 -> 80 * (50/100) = 40
    // gpa 4 -> 4 * (50/4) = 50
    // total = 90
    expect(computeTransferScore('80', '4')).toBe(90)
  })

  it('uses a custom percentage split', () => {
    // 70/30 split: weighted 80 -> 80 * 0.7 = 56 ; gpa 4 -> 4 * (30/4) = 30 ; total = 86
    expect(computeTransferScore('80', '4', '70', '30')).toBe(86)
  })

  it('returns null when the weighted score is <= 0', () => {
    expect(computeTransferScore('0', '4')).toBeNull()
    expect(computeTransferScore('-5', '4')).toBeNull()
  })

  it('returns null when the cumulative GPA is <= 0', () => {
    expect(computeTransferScore('80', '0')).toBeNull()
    expect(computeTransferScore('80', '-1')).toBeNull()
  })

  it('returns null for empty / non-numeric input (parses to 0)', () => {
    expect(computeTransferScore('', '')).toBeNull()
    expect(computeTransferScore('abc', 'xyz')).toBeNull()
  })

  it('supports Arabic-Indic numerals in all fields', () => {
    // weighted ٨٠ = 80, gpa ٤ = 4, default split -> 90
    expect(computeTransferScore('٨٠', '٤')).toBe(90)
  })

  it('handles fractional weighted score and GPA', () => {
    // weighted 75.5 -> 37.75 ; gpa 3.5 -> 3.5 * 12.5 = 43.75 ; total = 81.5
    expect(computeTransferScore('75.5', '3.5')).toBe(81.5)
  })
})
