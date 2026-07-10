<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Services\Calculators\GpaCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * UQU 4.0-scale GPA calculation — same math as the site's GPA calculator
 * (حاسبة المعدل). Read-only, pure computation.
 */
class CalculateGpaTool implements Tool
{
    use GatedByAiSettings;

    public function __construct(private readonly GpaCalculator $calculator) {}

    public function description(): Stringable|string
    {
        return 'Calculate a semester or cumulative GPA on the UQU 4.0 scale (حساب المعدل الفصلي أو التراكمي لجامعة أم القرى). '
            .'Pass each course\'s credit hours and letter grade. Grade points: A+ = 4, A = 3.75, B+ = 3.5, B = 3, C+ = 2.5, C = 2, D+ = 1.5, D = 1, F = 0. '
            .'Rows with zero credits or an unknown grade are ignored, exactly like the site\'s GPA calculator.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $courses = $request->array('courses');

        if ($courses === []) {
            return 'يرجى إدخال قائمة المقررات. Provide at least one course with credits and a grade.';
        }

        $rows = [];

        foreach ($courses as $course) {
            if (! is_array($course)) {
                continue;
            }

            $rows[] = [
                'credits' => $this->scalarToString($course['credits'] ?? ''),
                'grade' => strtoupper($this->scalarToString($course['grade'] ?? '')),
            ];
        }

        $result = $this->calculator->calculate($rows);

        if ($result->totalCredits <= 0) {
            return 'لم يتم احتساب أي مقرر — تأكد من الساعات والتقديرات. No course was counted: every row needs credits > 0 and a valid letter grade (A+, A, B+, B, C+, C, D+, D, F).';
        }

        return implode("\n", [
            "المعدل (GPA): {$result->gpa} من 4",
            "المعدل التقريبي (approximate): {$result->approximateGpa}",
            "الساعات المحتسبة (credits counted): {$result->totalCredits}",
            "النقاط (grade points): {$result->totalPoints}",
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'courses' => $schema->array()
                ->description('The list of courses to include.')
                ->items($schema->object([
                    'credits' => $schema->string()
                        ->description('Credit hours of the course, e.g. "3". Arabic-Indic digits are accepted.')
                        ->required(),
                    'grade' => $schema->string()
                        ->description('The letter grade.')
                        ->enum(['A+', 'A', 'B+', 'B', 'C+', 'C', 'D+', 'D', 'F'])
                        ->required(),
                ]))
                ->required(),
        ];
    }

    private function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
