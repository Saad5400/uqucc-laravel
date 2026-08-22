<?php

namespace App\Models\StudentGroup;

/**
 * The campus section a {@see GroupSupervisor} serves. Umm Al-Qura's colleges
 * are split into a men's and a women's section, and a student is normally only
 * admitted by a supervisor of their own section — so the public page picks one
 * supervisor per section rather than one overall.
 *
 * {@see self::Both} is for the lists that are published as one mixed roster
 * (the general newcomer lists), where no section split is advertised. A group
 * is free to fill only the sections it has; the page renders the rest away.
 */
enum SupervisorSection: string
{
    case Both = 'both';
    case Men = 'men';
    case Women = 'women';

    /** The Arabic label shown on the public page and in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Both => 'للشطرين',
            self::Men => 'شطر الطلاب',
            self::Women => 'شطر الطالبات',
        };
    }

    /**
     * Whether this section should be shown to someone filtering for $filter.
     * A «للشطرين» roster answers to both filters — that is the point of it.
     */
    public function matches(self $filter): bool
    {
        return $this === $filter || $this === self::Both || $filter === self::Both;
    }

    /**
     * All sections in display order.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Both, self::Men, self::Women];
    }

    /**
     * The valid values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $section) => $section->value, self::ordered());
    }
}
