/**
 * Shared Arabic-UI formatters. Numbers keep Latin digits and are meant to be
 * rendered inside `dir="ltr"` islands with `tabular-nums` in the RTL layout.
 */

export function formatNumber(value: number): string {
    return value.toLocaleString('en-US');
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

const dateTimeFormatter = new Intl.DateTimeFormat('ar', { dateStyle: 'medium', timeStyle: 'short' });

export function formatDateTime(iso: string): string {
    return dateTimeFormatter.format(new Date(iso));
}
