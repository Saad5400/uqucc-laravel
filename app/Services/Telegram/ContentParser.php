<?php

namespace App\Services\Telegram;

use Carbon\Carbon;

class ContentParser
{
    /**
     * Parse content for inline buttons and extract message text.
     *
     * Button format: (button text|url)
     * Row layout format: [صف:X-Y-Z] where X, Y, Z are buttons per row
     *
     * @return array{message: string, buttons: array, row_layout: array}
     */
    public function parseContent(string $content): array
    {
        $lines = explode("\n", $content);
        $message = [];
        $buttons = [];
        $rowLayout = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Check for row layout pattern [صف:X-Y-Z]
            if (preg_match('/^\[صف:([0-9\-]+)\]$/', $trimmedLine, $matches)) {
                $rowLayout = array_map('intval', explode('-', $matches[1]));

                continue;
            }

            // Check for button pattern (text|url)
            if (preg_match('/^\((.+?)\|(.+?)\)$/', $trimmedLine, $matches)) {
                $buttons[] = [
                    'text' => trim($matches[1]),
                    'url' => trim($matches[2]),
                ];

                continue;
            }

            // Regular message content
            $message[] = $line;
        }

        // Process dates in the message
        $messageText = implode("\n", $message);
        $messageText = $this->processDates($messageText);

        return [
            'message' => trim($messageText),
            'buttons' => $buttons,
            'row_layout' => $rowLayout,
        ];
    }

    /**
     * Convert buttons array to Telegram inline keyboard format.
     *
     * @param  array  $buttons  Array of ['text' => string, 'url' => string]
     * @param  array  $rowLayout  Array of integers specifying buttons per row
     * @return array Telegram inline keyboard markup
     */
    public function buildInlineKeyboard(array $buttons, array $rowLayout = []): array
    {
        if (empty($buttons)) {
            return [];
        }

        $keyboard = [];
        $buttonIndex = 0;

        if (empty($rowLayout)) {
            // Default: 2 buttons per row
            $rowLayout = array_fill(0, ceil(count($buttons) / 2), 2);
        }

        foreach ($rowLayout as $buttonsInRow) {
            $row = [];
            for ($i = 0; $i < $buttonsInRow && $buttonIndex < count($buttons); $i++) {
                $button = $buttons[$buttonIndex];
                $row[] = [
                    'text' => $button['text'],
                    'url' => $button['url'],
                ];
                $buttonIndex++;
            }
            if (! empty($row)) {
                $keyboard[] = $row;
            }
        }

        // Add remaining buttons (2 per row)
        while ($buttonIndex < count($buttons)) {
            $row = [];
            for ($i = 0; $i < 2 && $buttonIndex < count($buttons); $i++) {
                $button = $buttons[$buttonIndex];
                $row[] = [
                    'text' => $button['text'],
                    'url' => $button['url'],
                ];
                $buttonIndex++;
            }
            if (! empty($row)) {
                $keyboard[] = $row;
            }
        }

        return $keyboard;
    }

    /**
     * Process date placeholders in content.
     *
     * Gregorian format: {Y-M-D H:m [ص/م]}
     * Hijri format: <Y-M-D H:m [ص/م]>
     * Recurring: * for year/month/day
     */
    public function processDates(string $content): string
    {
        // Process Gregorian dates {Y-M-D H:m [ص/م]} or {Y-M-D}
        $content = preg_replace_callback(
            '/\{(\*|\d{4})-(\*|\d{1,2})-(\*|\d{1,2})(?:\s+(\d{1,2}):(\d{2})(?:\s*(ص|م|AM|PM))?)?\}/',
            function ($matches) {
                return $this->formatGregorianDate($matches);
            },
            $content
        );

        // Process Hijri dates <Y-M-D H:m [ص/م]> or <Y-M-D>
        $content = preg_replace_callback(
            '/<(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+(\d{1,2}):(\d{2})(?:\s*(ص|م|AM|PM))?)?>/',
            function ($matches) {
                return $this->formatHijriDate($matches);
            },
            $content
        );

        return $content;
    }

    /**
     * Format a Gregorian date from regex matches.
     */
    protected function formatGregorianDate(array $matches): string
    {
        $now = Carbon::now();

        $year = $matches[1] === '*' ? $now->year : (int) $matches[1];
        $month = $matches[2] === '*' ? $now->month : (int) $matches[2];
        $day = $matches[3] === '*' ? $now->day : (int) $matches[3];

        // Handle recurring dates - calculate next occurrence
        if ($matches[1] === '*' || $matches[2] === '*' || $matches[3] === '*') {
            $targetDate = Carbon::create($year, $month, $day);

            // If yearly recurring and date has passed, use next year
            if ($matches[1] === '*' && $targetDate->isPast()) {
                $targetDate->addYear();
            }

            // If monthly recurring and date has passed, use next month
            if ($matches[2] === '*' && $targetDate->isPast()) {
                $targetDate->addMonth();
            }

            $year = $targetDate->year;
            $month = $targetDate->month;
            $day = $targetDate->day;
        }

        $date = Carbon::create($year, $month, $day);

        // Format with Arabic month names
        $arabicMonths = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        $formattedDate = $day.' '.$arabicMonths[$month].' '.$year;

        // Add time if provided
        if (isset($matches[4]) && isset($matches[5])) {
            $hour = (int) $matches[4];
            $minute = str_pad($matches[5], 2, '0', STR_PAD_LEFT);
            $period = $matches[6] ?? null;

            if ($period) {
                $periodArabic = in_array($period, ['ص', 'AM']) ? 'ص' : 'م';
                $formattedDate .= ' - '.$hour.':'.$minute.' '.$periodArabic;
            } else {
                $formattedDate .= ' - '.$hour.':'.$minute;
            }
        }

        // Calculate countdown
        $countdown = $this->calculateCountdown($date);
        if ($countdown) {
            $formattedDate .= ' ('.$countdown.')';
        }

        return $formattedDate;
    }

    /**
     * Format a Hijri date from regex matches.
     * Note: Requires islamic-network/php-hijri-date package for accurate conversion.
     */
    protected function formatHijriDate(array $matches): string
    {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        $hijriMonths = [
            1 => 'محرم', 2 => 'صفر', 3 => 'ربيع الأول', 4 => 'ربيع الثاني',
            5 => 'جمادى الأولى', 6 => 'جمادى الآخرة', 7 => 'رجب', 8 => 'شعبان',
            9 => 'رمضان', 10 => 'شوال', 11 => 'ذو القعدة', 12 => 'ذو الحجة',
        ];

        $formattedDate = $day.' '.$hijriMonths[$month].' '.$year.' هـ';

        // Add time if provided
        if (isset($matches[4]) && isset($matches[5])) {
            $hour = (int) $matches[4];
            $minute = str_pad($matches[5], 2, '0', STR_PAD_LEFT);
            $period = $matches[6] ?? null;

            if ($period) {
                $periodArabic = in_array($period, ['ص', 'AM']) ? 'ص' : 'م';
                $formattedDate .= ' - '.$hour.':'.$minute.' '.$periodArabic;
            } else {
                $formattedDate .= ' - '.$hour.':'.$minute;
            }
        }

        return $formattedDate;
    }

    /**
     * Calculate countdown from now to target date.
     */
    protected function calculateCountdown(Carbon $targetDate): ?string
    {
        $now = Carbon::now();
        $diff = $now->diff($targetDate);

        if ($targetDate->isPast()) {
            return 'مضى';
        }

        $parts = [];

        if ($diff->y > 0) {
            $parts[] = $diff->y.' سنة';
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m.' شهر';
        }
        if ($diff->d > 0) {
            $parts[] = $diff->d.' يوم';
        }

        if (empty($parts)) {
            return 'اليوم';
        }

        return 'باقي '.implode(' و ', $parts);
    }

    /**
     * Convert parsed buttons to the format used by quick_response_buttons.
     */
    public function convertButtonsToQuickResponseFormat(array $buttons, array $rowLayout = []): array
    {
        if (empty($buttons)) {
            return [];
        }

        $result = [];
        $buttonIndex = 0;

        // Determine size for each button based on row layout
        if (! empty($rowLayout)) {
            foreach ($rowLayout as $buttonsInRow) {
                $size = match ($buttonsInRow) {
                    1 => 'full',
                    2 => 'half',
                    3 => 'third',
                    default => 'half',
                };

                for ($i = 0; $i < $buttonsInRow && $buttonIndex < count($buttons); $i++) {
                    $button = $buttons[$buttonIndex];
                    $result[] = [
                        'text' => $button['text'],
                        'url' => $button['url'],
                        'size' => $size,
                    ];
                    $buttonIndex++;
                }
            }
        }

        // Remaining buttons get default 'half' size
        while ($buttonIndex < count($buttons)) {
            $button = $buttons[$buttonIndex];
            $result[] = [
                'text' => $button['text'],
                'url' => $button['url'],
                'size' => 'half',
            ];
            $buttonIndex++;
        }

        return $result;
    }
}
