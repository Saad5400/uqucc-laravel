<?php

namespace App\Models\StudentGroup;

/**
 * A College of Computing degree programme. Specialized groups are one per
 * (major, branch); a group with no major is the cohort's general group.
 *
 * The list is a stable university fact rather than user data, so it lives here
 * instead of in a table — adding a programme is one case plus its label, and
 * every filter, label and select picks it up.
 */
enum Major: string
{
    case ComputerScience = 'computer_science';
    case ComputerEngineering = 'computer_engineering';
    case SoftwareEngineering = 'software_engineering';
    case Cybersecurity = 'cybersecurity';
    case ArtificialIntelligence = 'artificial_intelligence';
    case DataScience = 'data_science';
    case HumanComputerInteraction = 'human_computer_interaction';

    public function label(): string
    {
        return match ($this) {
            self::ComputerScience => 'علوم الحاسب الآلي',
            self::ComputerEngineering => 'هندسة الحاسب والشبكات',
            self::SoftwareEngineering => 'هندسة البرمجيات',
            self::Cybersecurity => 'الأمن السيبراني',
            self::ArtificialIntelligence => 'الذكاء الاصطناعي',
            self::DataScience => 'علم البيانات',
            self::HumanComputerInteraction => 'تفاعل الإنسان مع الحاسب',
        };
    }

    /**
     * All majors in the order the college lists them.
     *
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
        return array_map(fn (self $major) => $major->value, self::cases());
    }
}
