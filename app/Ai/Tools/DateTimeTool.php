<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use IntlDateFormatter;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Gives the assistant a real clock and calendar. The model has no reliable
 * sense of "now", so every date/time question must go through this tool
 * rather than being guessed. Read-only, pure computation, always resolved in
 * the site's timezone (config('app.timezone'), Asia/Riyadh) and reported in
 * both the Gregorian (ميلادي) and Umm al-Qura Hijri (هجري) calendars.
 */
class DateTimeTool implements Tool
{
    use GatedByAiSettings;

    /**
     * @var list<string>
     */
    private const UNITS = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'];

    public function description(): Stringable|string
    {
        return 'Get the current date and time, or do date/time arithmetic (معرفة التاريخ والوقت الآن أو إجراء عمليات على التواريخ). '
            .'Always use this instead of guessing "today", "now", the day of the week, or how far apart two dates are. '
            ."Everything is resolved in the site's timezone (Asia/Riyadh) and reported in both the Gregorian (ميلادي) and Hijri Umm al-Qura (هجري) calendars. Operations: "
            .'"now" (current date/time), "add"/"subtract" (shift a datetime by an amount and unit), "difference" (time between two datetimes). '
            .'Datetimes may be written naturally, e.g. "2026-07-15", "2026-07-15 14:30", or "now".';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $operation = strtolower(trim($this->scalarToString($request['operation'] ?? 'now')));

        return match ($operation) {
            '', 'now' => $this->describe(CarbonImmutable::now($this->timezone())),
            'add', 'subtract' => $this->shift($request, $operation),
            'diff', 'difference' => $this->difference($request),
            default => 'العملية غير معروفة. Unknown operation — use one of: now, add, subtract, difference.',
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('What to do. Defaults to "now".')
                ->enum(['now', 'add', 'subtract', 'difference']),
            'datetime' => $schema->string()
                ->description('The base datetime for "add"/"subtract", or the first datetime for "difference". Any natural format, e.g. "2026-07-15 14:30" or "now". Defaults to the current date/time.'),
            'datetime2' => $schema->string()
                ->description('The second datetime, required for "difference".'),
            'amount' => $schema->integer()
                ->description('How many units to add or subtract (required for "add"/"subtract"), e.g. 3.'),
            'unit' => $schema->string()
                ->description('The unit to add or subtract (required for "add"/"subtract").')
                ->enum(self::UNITS),
        ];
    }

    private function shift(Request $request, string $operation): string
    {
        $base = $this->parse($this->scalarToString($request['datetime'] ?? 'now'));

        if ($base === null) {
            return 'تعذّر فهم التاريخ المُدخل. Could not understand the "datetime" value.';
        }

        $unit = strtolower(trim($this->scalarToString($request['unit'] ?? '')));

        if (! in_array($unit, self::UNITS, true)) {
            return 'الوحدة غير صالحة. "unit" must be one of: '.implode(', ', self::UNITS).'.';
        }

        if (! is_numeric($request['amount'] ?? null)) {
            return 'المقدار مطلوب. "amount" is required and must be a whole number.';
        }

        $amount = (int) $request['amount'];

        $result = $operation === 'add'
            ? $base->add($unit, $amount)
            : $base->sub($unit, $amount);

        $verb = $operation === 'add' ? 'add' : 'subtract';

        return "{$verb} {$amount} {$unit} → \n".$this->describe($result);
    }

    private function difference(Request $request): string
    {
        $from = $this->parse($this->scalarToString($request['datetime'] ?? 'now'));
        $to = $this->parse($this->scalarToString($request['datetime2'] ?? 'now'));

        if ($from === null || $to === null) {
            return 'تعذّر فهم أحد التاريخين. Provide two datetimes ("datetime" and "datetime2") in an understandable format.';
        }

        $interval = $from->diff($to);
        $totalDays = abs($from->diffInDays($to));
        $breakdown = $interval->forHumans(['parts' => 4, 'join' => true]);
        $direction = $to->greaterThanOrEqualTo($from) ? 'later than' : 'earlier than';

        return implode("\n", [
            'من (from): '.$this->gregorian($from),
            'إلى (to): '.$this->gregorian($to),
            "الفرق (difference): {$breakdown}",
            'بالأيام (in days): '.round($totalDays, 2),
            "الثاني {$direction} الأول (datetime2 is {$direction} datetime).",
        ]);
    }

    private function describe(CarbonInterface $moment): string
    {
        return implode("\n", [
            'الميلادي (Gregorian): '.$this->gregorian($moment),
            'اليوم (weekday): '.$moment->locale('ar')->translatedFormat('l').' / '.$moment->locale('en')->translatedFormat('l'),
            'الهجري (Hijri, Umm al-Qura): '.$this->hijri($moment),
            'المنطقة الزمنية (timezone): '.$moment->getTimezone()->getName(),
            'ISO 8601: '.$moment->toIso8601String(),
        ]);
    }

    private function gregorian(CarbonInterface $moment): string
    {
        return $moment->format('Y-m-d H:i');
    }

    private function hijri(CarbonInterface $moment): string
    {
        $formatter = new IntlDateFormatter(
            'ar_SA@calendar=islamic-umalqura',
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            $moment->getTimezone()->getName(),
            IntlDateFormatter::TRADITIONAL,
        );

        return (string) $formatter->format($moment);
    }

    private function parse(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return CarbonImmutable::now($this->timezone());
        }

        try {
            return CarbonImmutable::parse($value, $this->timezone());
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    private function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
