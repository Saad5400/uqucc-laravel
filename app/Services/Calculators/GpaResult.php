<?php

namespace App\Services\Calculators;

/**
 * GPA statistics over the counted course rows — mirrors the `GpaStats`
 * interface in resources/js/lib/calculators/gpa.ts.
 */
final readonly class GpaResult
{
    public function __construct(
        public float $gpa,
        public float $approximateGpa,
        public float $totalCredits,
        public float $totalPoints,
    ) {}

    /**
     * @return array{gpa: float, approximate_gpa: float, total_credits: float, total_points: float}
     */
    public function toArray(): array
    {
        return [
            'gpa' => $this->gpa,
            'approximate_gpa' => $this->approximateGpa,
            'total_credits' => $this->totalCredits,
            'total_points' => $this->totalPoints,
        ];
    }
}
