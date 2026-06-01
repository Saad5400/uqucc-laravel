import { describe, expect, it } from 'vitest'
import {
  adjustPaymentDateForWeekend,
  calculateNextPaymentDate,
  calculateTimeLeft,
  getCurrentRiyadhDate,
  isToday,
  PAYMENT_DAY,
  RIYADH_TIMEZONE
} from './reward'

describe('reward / Riyadh-timezone calculator', () => {
  it('exposes the documented constants', () => {
    expect(RIYADH_TIMEZONE).toBe('Asia/Riyadh')
    expect(PAYMENT_DAY).toBe(27)
  })

  describe('getCurrentRiyadhDate', () => {
    it('shifts a UTC instant forward by the +03:00 Riyadh offset', () => {
      // 2026-01-26T22:00:00Z is 2026-01-27T01:00:00 in Riyadh
      const riyadh = getCurrentRiyadhDate(new Date('2026-01-26T22:00:00Z'))
      expect(riyadh.getUTCFullYear()).toBe(2026)
      expect(riyadh.getUTCMonth()).toBe(0)
      expect(riyadh.getUTCDate()).toBe(27)
      expect(riyadh.getUTCHours()).toBe(1)
    })
  })

  describe('adjustPaymentDateForWeekend', () => {
    it('leaves a weekday payment date untouched', () => {
      // 2026-01-27 is a Tuesday
      const d = adjustPaymentDateForWeekend(2026, 0, 27)
      expect(d.getUTCDate()).toBe(27)
      expect(d.getUTCDay()).toBe(2)
    })

    it('moves a Friday payment date back to Thursday', () => {
      // 2026-03-27 is a Friday -> 2026-03-26 (Thursday)
      const d = adjustPaymentDateForWeekend(2026, 2, 27)
      expect(d.getUTCDate()).toBe(26)
      expect(d.getUTCDay()).toBe(4)
    })

    it('moves a Saturday payment date forward to Sunday', () => {
      // 2026-06-27 is a Saturday -> 2026-06-28 (Sunday)
      const d = adjustPaymentDateForWeekend(2026, 5, 27)
      expect(d.getUTCDate()).toBe(28)
      expect(d.getUTCDay()).toBe(0)
    })
  })

  describe('calculateNextPaymentDate', () => {
    it('returns this month when today is before the payment day', () => {
      const d = calculateNextPaymentDate(new Date('2026-01-10T12:00:00Z'))
      expect(d.toISOString()).toBe('2026-01-27T00:00:00.000Z')
    })

    it('rolls over to next month (with weekend adjustment) once the payment day has passed', () => {
      // After Jan 27 -> Feb 27 2026, which is a Friday -> adjusted to Feb 26
      const d = calculateNextPaymentDate(new Date('2026-01-28T12:00:00Z'))
      expect(d.toISOString()).toBe('2026-02-26T00:00:00.000Z')
    })

    it('rolls over across the year boundary', () => {
      const d = calculateNextPaymentDate(new Date('2026-12-28T12:00:00Z'))
      expect(d.toISOString()).toBe('2027-01-27T00:00:00.000Z')
    })
  })

  describe('isToday', () => {
    it('is true when the payment date matches the current Riyadh day', () => {
      const now = new Date('2026-01-27T09:00:00Z')
      const payment = calculateNextPaymentDate(now)
      expect(isToday(payment, now)).toBe(true)
    })

    it('is false on a different day', () => {
      const now = new Date('2026-01-10T09:00:00Z')
      const payment = calculateNextPaymentDate(now)
      expect(isToday(payment, now)).toBe(false)
    })
  })

  describe('calculateTimeLeft', () => {
    it('breaks the remaining duration into days/hours/minutes/seconds', () => {
      const now = new Date('2026-01-10T00:00:00Z')
      // target = Riyadh 2026-01-12 02:03:04 represented as that UTC wall clock
      const target = new Date(Date.UTC(2026, 0, 12, 5, 3, 4))
      // current Riyadh = 2026-01-10 03:00:00 (UTC+3) -> diff = 2d 02:03:04
      const left = calculateTimeLeft(target, now)
      expect(left).toEqual({ days: 2, hours: 2, minutes: 3, seconds: 4 })
    })

    it('clamps to zero when the target is in the past', () => {
      const now = new Date('2026-02-01T00:00:00Z')
      const target = new Date(Date.UTC(2026, 0, 1))
      expect(calculateTimeLeft(target, now)).toEqual({
        days: 0,
        hours: 0,
        minutes: 0,
        seconds: 0
      })
    })
  })
})
