<?php

namespace App\Models\StudentGroup;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * Someone who admits newcomers to a {@see StudentGroup}, reachable on Telegram,
 * on WhatsApp, or on both — the college's own announcements mix the two freely,
 * and a supervisor who publishes a phone number will not answer on Telegram.
 *
 * `is_available` is the field that earns its keep: supervisors go quiet during
 * exams and come back afterwards, and toggling the flag drops them out of the
 * public rotation while keeping their row — the alternative people reach for is
 * deleting and re-adding them, which loses the ordering every time.
 */
class GroupSupervisor extends Model implements Sortable
{
    /** @use HasFactory<\Database\Factories\StudentGroup\GroupSupervisorFactory> */
    use HasFactory, LogsActivity, SortableTrait;

    protected $table = 'group_supervisors';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(Cohort::CACHE_KEY));
        static::deleted(fn () => Cache::forget(Cohort::CACHE_KEY));
    }

    protected $fillable = [
        'student_group_id',
        'name',
        'telegram_username',
        'whatsapp_number',
        'section',
        'is_available',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'student_group_id' => 'integer',
            'section' => SupervisorSection::class,
            'is_available' => 'boolean',
            'order' => 'integer',
        ];
    }

    public array $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'student_group_id');
    }

    /**
     * Store the bare handle whatever the panel was given.
     *
     * Admins copy whatever Telegram put on their clipboard — «@ahmad», the
     * profile URL, sometimes the URL with a `?start=` tail — and all three name
     * the same person. Normalizing on write instead of on read keeps one stored
     * shape, so `t.me/{username}` is always a valid link and the same person
     * can never be stored twice under two spellings.
     */
    protected function telegramUsername(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::blankToNull(self::normalizeUsername((string) $value)),
        );
    }

    /**
     * Store phone numbers in one dialable international shape, whichever way
     * the announcement wrote them («0507487697», «+966 50 748 7697», «966…»).
     */
    protected function whatsappNumber(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::blankToNull(self::normalizeWhatsapp((string) $value)),
        );
    }

    /**
     * Reduce any accepted way of writing a Telegram handle to the bare
     * username: no `@`, no host, no path or query leftovers.
     */
    public static function normalizeUsername(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^(?:https?://)?(?:www\.)?(?:t(?:elegram)?\.me|telegram\.dog)/#i', '', $value) ?? $value;
        $value = ltrim($value, '@/');
        $value = explode('?', $value)[0];

        return trim($value, "/ \t\n\r\0\x0B");
    }

    /**
     * Reduce a phone number to digits in international form, without a `+`.
     *
     * Saudi mobiles are written locally as «05XXXXXXXX»; wa.me needs the
     * country code, so a leading zero becomes 966 here rather than at every
     * place that builds a link.
     */
    public static function normalizeWhatsapp(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return '';
        }

        $digits = preg_replace('/^00/', '', $digits) ?? $digits;

        if (str_starts_with($digits, '966')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '966'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '5')) {
            return '966'.$digits;
        }

        return $digits;
    }

    /** The public profile URL a Telegram contact is sent to. */
    public function telegramUrl(): ?string
    {
        return $this->telegram_username === null ? null : 'https://t.me/'.$this->telegram_username;
    }

    /** The chat URL a WhatsApp contact is sent to. */
    public function whatsappUrl(): ?string
    {
        return $this->whatsapp_number === null ? null : 'https://wa.me/'.$this->whatsapp_number;
    }

    /**
     * The number as a Saudi reader expects to see it — «0507487697» — falling
     * back to a plain international form for anything outside +966.
     */
    public function whatsappDisplay(): ?string
    {
        if ($this->whatsapp_number === null) {
            return null;
        }

        return str_starts_with($this->whatsapp_number, '966')
            ? '0'.substr($this->whatsapp_number, 3)
            : '+'.$this->whatsapp_number;
    }

    /**
     * Every way to reach this supervisor, Telegram first — it is the one the
     * college's own lists lead with, and it needs no phone number saved.
     *
     * @return array<int, array{kind: string, handle: string, url: string}>
     */
    public function contacts(): array
    {
        $contacts = [];

        if ($this->telegram_username !== null) {
            $contacts[] = [
                'kind' => 'telegram',
                'handle' => '@'.$this->telegram_username,
                'url' => (string) $this->telegramUrl(),
            ];
        }

        if ($this->whatsapp_number !== null) {
            $contacts[] = [
                'kind' => 'whatsapp',
                'handle' => (string) $this->whatsappDisplay(),
                'url' => (string) $this->whatsappUrl(),
            ];
        }

        return $contacts;
    }

    /** Normalizers return '' for "nothing given"; the column wants null. */
    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'telegram_username', 'whatsapp_number', 'section', 'is_available', 'order', 'student_group_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
