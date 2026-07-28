/**
 * Shared Arabic-UI formatters. Numbers keep Latin digits and are meant to be
 * rendered inside `dir="ltr"` islands with `tabular-nums` in the RTL layout.
 */

export function formatNumber(value: number): string {
    return value.toLocaleString('en-US');
}

const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB'];

/** 1234567 → "1.2 MB" — Latin digits/units, meant for a `dir="ltr"` island. */
export function formatFileSize(bytes: number): string {
    let size = bytes;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < FILE_SIZE_UNITS.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }

    const rounded = unitIndex === 0 ? String(size) : size.toFixed(1).replace(/\.0$/, '');

    return `${rounded} ${FILE_SIZE_UNITS[unitIndex]}`;
}

const relativeTimeFormatter = new Intl.RelativeTimeFormat('ar', { numeric: 'auto' });

const TIME_DIVISIONS: { amount: number; unit: Intl.RelativeTimeFormatUnit }[] = [
    { amount: 60, unit: 'seconds' },
    { amount: 60, unit: 'minutes' },
    { amount: 24, unit: 'hours' },
    { amount: 7, unit: 'days' },
    { amount: 4.34524, unit: 'weeks' },
    { amount: 12, unit: 'months' },
    { amount: Number.POSITIVE_INFINITY, unit: 'years' },
];

export function formatRelativeTime(iso: string): string {
    let duration = (new Date(iso).getTime() - Date.now()) / 1000;

    for (const division of TIME_DIVISIONS) {
        if (Math.abs(duration) < division.amount) {
            return relativeTimeFormatter.format(Math.round(duration), division.unit);
        }

        duration /= division.amount;
    }

    return '';
}

const shortDateFormatter = new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'short' });

/** "2026-07-10" → a short Arabic day/month label ("١٠ يوليو"). */
export function formatShortDate(isoDate: string): string {
    return shortDateFormatter.format(new Date(`${isoDate}T00:00:00`));
}

const weekdayDateFormatter = new Intl.DateTimeFormat('ar', { weekday: 'long', day: 'numeric', month: 'long' });

/** "2026-07-29" → a weekday-qualified Arabic date ("الأربعاء ٢٩ يوليو"). */
export function formatWeekdayDate(isoDate: string): string {
    return weekdayDateFormatter.format(new Date(`${isoDate}T00:00:00`));
}

/**
 * "2026-07-29" relative to today as a whole-day phrase ("اليوم", "غداً",
 * "بعد ٣ أيام") — calendar-day arithmetic, not elapsed hours, so a date is
 * never called "tomorrow" just because it is 20 hours away.
 */
export function formatDayOffset(isoDate: string, today: string): string {
    const days = Math.round((new Date(`${isoDate}T00:00:00Z`).getTime() - new Date(`${today}T00:00:00Z`).getTime()) / 86_400_000);

    return relativeTimeFormatter.format(days, 'day');
}

const dateTimeFormatter = new Intl.DateTimeFormat('ar', { dateStyle: 'medium', timeStyle: 'short' });

export function formatDateTime(iso: string): string {
    return dateTimeFormatter.format(new Date(iso));
}
