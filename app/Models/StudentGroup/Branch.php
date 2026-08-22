<?php

namespace App\Models\StudentGroup;

/**
 * An Umm Al-Qura campus the college teaches at. The men's and women's sections
 * of the main branch sit on different campuses (العابدية and الزاهر), which is
 * carried by {@see SupervisorSection} rather than by a branch of its own — a
 * student picks their branch, then their section, never a campus.
 */
enum Branch: string
{
    case Main = 'main';
    case Jamoum = 'jamoum';
    case Qunfudah = 'qunfudah';
    case Layth = 'layth';
    case Adham = 'adham';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'الفرع الرئيسي — مكة المكرمة',
            self::Jamoum => 'فرع الجموم',
            self::Qunfudah => 'فرع القنفذة',
            self::Layth => 'فرع الليث',
            self::Adham => 'فرع أضم',
        };
    }

    /** The short form used where the branch is already in context. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Main => 'الرئيسي',
            self::Jamoum => 'الجموم',
            self::Qunfudah => 'القنفذة',
            self::Layth => 'الليث',
            self::Adham => 'أضم',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $branch) => $branch->value, self::cases());
    }
}
