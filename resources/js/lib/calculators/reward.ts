// Next-reward-date / Riyadh-timezone calculator core logic.
// Extracted verbatim from NextRewardPage.vue so it can be unit-tested and
// reused. Behavior must remain identical to the original inline copy.
//
// Dates are represented as UTC Date objects whose UTC components mirror the
// wall-clock time in Riyadh (a common trick to keep server/client consistent).

export const RIYADH_TIMEZONE = 'Asia/Riyadh'
export const PAYMENT_DAY = 27

export interface TimeLeft {
  days: number
  hours: number
  minutes: number
  seconds: number
}

/**
 * Gets the current date and time in Riyadh timezone as a UTC Date object.
 * The optional `now` argument (defaults to the real current instant) makes the
 * function deterministically testable.
 */
export const getCurrentRiyadhDate = (now: Date = new Date()): Date => {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: RIYADH_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  })

  const parts = formatter.formatToParts(now)
  const dateComponents = {
    year: parseInt(parts.find((p) => p.type === 'year')!.value),
    month: parseInt(parts.find((p) => p.type === 'month')!.value) - 1, // Month is 0-indexed
    day: parseInt(parts.find((p) => p.type === 'day')!.value),
    hour: parseInt(parts.find((p) => p.type === 'hour')!.value),
    minute: parseInt(parts.find((p) => p.type === 'minute')!.value),
    second: parseInt(parts.find((p) => p.type === 'second')!.value)
  }

  return new Date(
    Date.UTC(
      dateComponents.year,
      dateComponents.month,
      dateComponents.day,
      dateComponents.hour,
      dateComponents.minute,
      dateComponents.second
    )
  )
}

/** Creates a UTC Date object representing a specific Riyadh date/time. */
export const createRiyadhDateInUTC = (
  year: number,
  month: number,
  day: number,
  hour = 0,
  minute = 0,
  second = 0
): Date => {
  return new Date(Date.UTC(year, month, day, hour, minute, second))
}

/**
 * Adjusts payment date if it falls on Friday or Saturday.
 * Friday moves to Thursday, Saturday moves to Sunday.
 */
export const adjustPaymentDateForWeekend = (year: number, month: number, day: number): Date => {
  const date = createRiyadhDateInUTC(year, month, day)
  const dayOfWeek = date.getUTCDay()

  if (dayOfWeek === 5) {
    // Friday
    return createRiyadhDateInUTC(year, month, day - 1) // Move to Thursday
  } else if (dayOfWeek === 6) {
    // Saturday
    return createRiyadhDateInUTC(year, month, day + 1) // Move to Sunday
  }

  return date
}

/**
 * Calculates the next payment date based on the given (or current) Riyadh time.
 * Returns a UTC Date object for consistent calculations.
 */
export const calculateNextPaymentDate = (now: Date = new Date()): Date => {
  const currentRiyadhDate = getCurrentRiyadhDate(now)
  const year = currentRiyadhDate.getUTCFullYear()
  const month = currentRiyadhDate.getUTCMonth()
  const day = currentRiyadhDate.getUTCDate()

  // Start with the payment day of current month
  let paymentDate = adjustPaymentDateForWeekend(year, month, PAYMENT_DAY)

  // Check if we need to move to next month
  const todayUTC = createRiyadhDateInUTC(year, month, day)

  if (todayUTC > paymentDate) {
    // Move to next month
    const nextMonth = month + 1
    const nextYear = nextMonth > 11 ? year + 1 : year
    const adjustedMonth = nextMonth > 11 ? 0 : nextMonth
    paymentDate = adjustPaymentDateForWeekend(nextYear, adjustedMonth, PAYMENT_DAY)
  }

  return paymentDate
}

/** Whether the given payment date is "today" in Riyadh. */
export const isToday = (paymentDate: Date, now: Date = new Date()): boolean => {
  const currentRiyadhDate = getCurrentRiyadhDate(now)
  return (
    currentRiyadhDate.getUTCFullYear() === paymentDate.getUTCFullYear() &&
    currentRiyadhDate.getUTCMonth() === paymentDate.getUTCMonth() &&
    currentRiyadhDate.getUTCDate() === paymentDate.getUTCDate()
  )
}

/** Remaining time until the target date, clamped at zero. */
export const calculateTimeLeft = (targetDate: Date, now: Date = new Date()): TimeLeft => {
  const current = getCurrentRiyadhDate(now)
  const difference = targetDate.getTime() - current.getTime()

  if (difference > 0) {
    return {
      days: Math.floor(difference / (1000 * 60 * 60 * 24)),
      hours: Math.floor((difference / (1000 * 60 * 60)) % 24),
      minutes: Math.floor((difference / 1000 / 60) % 60),
      seconds: Math.floor((difference / 1000) % 60)
    }
  } else {
    return { days: 0, hours: 0, minutes: 0, seconds: 0 }
  }
}
