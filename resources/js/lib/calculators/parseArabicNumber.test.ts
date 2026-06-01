import { describe, expect, it } from 'vitest'
import { parseArabicNumber } from './parseArabicNumber'

describe('parseArabicNumber', () => {
  it('parses plain Latin integers and decimals', () => {
    expect(parseArabicNumber('12')).toBe(12)
    expect(parseArabicNumber('3.5')).toBe(3.5)
  })

  it('converts Arabic-Indic digits to a number', () => {
    expect(parseArabicNumber('٣٫٥')).toBe(3.5)
    expect(parseArabicNumber('١٢٣')).toBe(123)
  })

  it('treats Arabic comma (،), decimal separator (٫) and Latin comma as a decimal point', () => {
    expect(parseArabicNumber('٣،٥')).toBe(3.5)
    expect(parseArabicNumber('3,5')).toBe(3.5)
    expect(parseArabicNumber('3٫5')).toBe(3.5)
  })

  it('trims surrounding whitespace', () => {
    expect(parseArabicNumber('  12 ')).toBe(12)
  })

  it('returns 0 for empty, default, and non-numeric input', () => {
    expect(parseArabicNumber('')).toBe(0)
    expect(parseArabicNumber()).toBe(0)
    expect(parseArabicNumber('abc')).toBe(0)
  })
})
