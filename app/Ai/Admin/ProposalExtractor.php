<?php

namespace App\Ai\Admin;

use App\Models\Ai\AdminPendingAction;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Derives the action cards of an assistant turn from the propose_* tools it
 * actually ran — the admin-side sibling of {@see \App\Ai\Chat\CitationExtractor}.
 * Parsing leans on the tools' stable "proposal_id: {ulid}" trailer (never on
 * model output, so a card is never hallucinated) and hydrates the persisted
 * actions so the client always shows the CURRENT status, even when an old
 * conversation is rehydrated after its proposals were confirmed or rejected.
 */
class ProposalExtractor
{
    private const PROPOSER_TOOLS = ['propose_page_change', 'propose_settings_change'];

    /**
     * @param  list<ToolResult>  $toolResults
     * @return list<array{id: string, type: string, summary: string, payload: array<string, mixed>, status: string, error: string|null}>
     */
    public function extract(array $toolResults): array
    {
        $ids = [];

        foreach ($toolResults as $result) {
            if (! in_array($result->name, self::PROPOSER_TOOLS, true)) {
                continue;
            }

            $text = $result->result;

            if ($text instanceof \Stringable) {
                $text = (string) $text;
            }

            if (is_string($text) && preg_match('/^proposal_id: (\S+)\s*$/mu', $text, $matches) === 1) {
                $ids[] = $matches[1];
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, AdminPendingAction> $proposals */
        $proposals = AdminPendingAction::query()->findMany($ids)->keyBy('id');

        $cards = [];

        foreach ($ids as $id) {
            if ($proposals->has($id)) {
                $cards[] = $proposals[$id]->toClientPayload();
            }
        }

        return $cards;
    }

    /**
     * As extract(), but from the tool_results arrays a stored conversation
     * message carries (rehydration path).
     *
     * @param  array<int, array<string, mixed>>  $storedToolResults
     * @return list<array{id: string, type: string, summary: string, payload: array<string, mixed>, status: string, error: string|null}>
     */
    public function extractFromStored(array $storedToolResults): array
    {
        $results = [];

        foreach ($storedToolResults as $stored) {
            if (is_array($stored) && isset($stored['id'], $stored['name'], $stored['arguments'])) {
                $results[] = ToolResult::fromArray($stored);
            }
        }

        return $this->extract($results);
    }
}
