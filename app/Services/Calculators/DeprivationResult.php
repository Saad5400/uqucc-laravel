<?php

namespace App\Services\Calculators;

/**
 * Deprivation (حرمان) statistics — mirrors the `DeprivationStats` interface
 * in resources/js/lib/calculators/deprivation.ts.
 */
final readonly class DeprivationResult
{
    public function __construct(
        public float $lectureWeight,
        public int $totalHours,
        public int $unexcusedLeft,
        public int $absenceLeft,
        public float $currentAbsenceRate,
        public bool $isDeprived,
    ) {}

    /**
     * @return array{lecture_weight: float, total_hours: int, unexcused_left: int, absence_left: int, current_absence_rate: float, is_deprived: bool}
     */
    public function toArray(): array
    {
        return [
            'lecture_weight' => $this->lectureWeight,
            'total_hours' => $this->totalHours,
            'unexcused_left' => $this->unexcusedLeft,
            'absence_left' => $this->absenceLeft,
            'current_absence_rate' => $this->currentAbsenceRate,
            'is_deprived' => $this->isDeprived,
        ];
    }
}
