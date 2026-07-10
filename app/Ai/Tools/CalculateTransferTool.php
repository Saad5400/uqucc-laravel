<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Services\Calculators\TransferCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Internal-transfer composite score (مركبة التحويل) — same math as the
 * site's transfer calculator. Read-only, pure computation.
 */
class CalculateTransferTool implements Tool
{
    use GatedByAiSettings;

    public function __construct(private readonly TransferCalculator $calculator) {}

    public function description(): Stringable|string
    {
        return 'Calculate the UQU internal-transfer composite score (حساب مركبة التحويل الداخلي بين التخصصات). '
            .'Combines the weighted high-school score (النسبة الموزونة, out of 100) with the cumulative GPA (المعدل التراكمي, out of 4) '
            .'using a percentage split that defaults to 50/50: score = weighted × (weighted_percentage / 100) + gpa × (gpa_percentage / 4).';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $score = $this->calculator->calculate(
            $this->scalarToString($request['weighted_score'] ?? ''),
            $this->scalarToString($request['cumulative_gpa'] ?? ''),
            $this->scalarToString($request['weighted_percentage'] ?? '50'),
            $this->scalarToString($request['gpa_percentage'] ?? '50'),
        );

        if ($score === null) {
            return 'يرجى إدخال نسبة موزونة ومعدل تراكمي أكبر من صفر. Both weighted_score and cumulative_gpa must be greater than zero.';
        }

        $rounded = round($score, 2);

        return "مركبة التحويل (transfer composite score): {$rounded} من 100";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'weighted_score' => $schema->number()
                ->description('The weighted score (النسبة الموزونة) out of 100.')
                ->required(),
            'cumulative_gpa' => $schema->number()
                ->description('The cumulative GPA (المعدل التراكمي) out of 4.')
                ->required(),
            'weighted_percentage' => $schema->number()
                ->description('Weight of the weighted score in the composite, as a percentage. Defaults to 50.'),
            'gpa_percentage' => $schema->number()
                ->description('Weight of the GPA in the composite, as a percentage. Defaults to 50.'),
        ];
    }

    private function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
