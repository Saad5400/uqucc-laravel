<?php

namespace App\Services\Telegram\Handlers;

use App\Services\TruthTableService;
use Telegram\Bot\Objects\Message;

class TruthTableHandler extends BaseHandler
{
    public function __construct(
        protected TruthTableService $truthTableService
    ) {
        parent::__construct(app(\Telegram\Bot\Api::class));
    }

    public function handle(Message $message): void
    {
        $text = $message->getText();

        // Check for truth table command in Arabic or English
        if (! $this->matches($message, '/^(جدول الحقيقة|truth table|جدول صحة)\s+(.+)$/i')) {
            return;
        }

        // Track command usage
        $this->trackCommand($message, 'truth_table');

        // Extract the formula from the message
        if (preg_match('/^(جدول الحقيقة|truth table|جدول صحة)\s+(.+)$/i', trim($text), $matches)) {
            $formula = trim($matches[2]);

            try {
                // Generate truth table
                $truthTable = $this->truthTableService->generate($formula);

                // Format as text
                $formattedTable = $this->truthTableService->formatAsText($truthTable);

                // Create response message
                $response = "<b>Truth Table for:</b> <code>{$this->escapeHtml($formula)}</code>\n\n";
                $response .= "<pre>{$this->escapeHtml($formattedTable)}</pre>\n\n";

                // Add analysis
                $response .= $this->getAnalysis($truthTable);

                // Add help text
                $response .= "\n<i>📝 Operators: ∧ (and, /\\, &&), ∨ (or, \\/, ||), ¬ (not, ~, !), → (implies, ->, =>), ↔ (iff, <->), ⊤ (T), ⊥ (F)</i>";

                $this->replyHtml($message, $response);
            } catch (\InvalidArgumentException $e) {
                $errorMessage = "❌ <b>Error:</b> {$this->escapeHtml($e->getMessage())}\n\n";
                $errorMessage .= "<i>Please check your formula and try again.</i>";
                $this->replyHtml($message, $errorMessage);
            } catch (\Throwable $e) {
                $errorMessage = "❌ <b>Error:</b> Failed to generate truth table.\n\n";
                $errorMessage .= "<i>Please check your formula and try again.</i>";
                $this->replyHtml($message, $errorMessage);
            }
        }
    }

    /**
     * Get analysis of the truth table.
     *
     * @param  array{variables: array<string>, table: array<array<mixed>>}  $truthTable
     */
    protected function getAnalysis(array $truthTable): string
    {
        if (empty($truthTable['table'])) {
            return '';
        }

        $allTrue = true;
        $allFalse = true;

        foreach ($truthTable['table'] as $row) {
            if (! $row['result']) {
                $allTrue = false;
            }
            if ($row['result']) {
                $allFalse = false;
            }
        }

        $analysis = '<b>Analysis:</b>'."\n";

        if ($allTrue) {
            $analysis .= '✅ <b>Tautology</b> - Always true';
        } elseif ($allFalse) {
            $analysis .= '❌ <b>Contradiction</b> - Always false';
        } else {
            $analysis .= '🔄 <b>Contingent</b> - Sometimes true, sometimes false';
        }

        $variableCount = count($truthTable['variables']);
        $rowCount = count($truthTable['table']);
        $analysis .= "\n📊 {$variableCount} variables, {$rowCount} rows";

        return $analysis;
    }
}
