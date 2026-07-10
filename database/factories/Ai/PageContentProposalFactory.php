<?php

namespace Database\Factories\Ai;

use App\Models\Ai\PageContentProposal;
use App\Models\Corpus\CorpusDocument;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageContentProposal>
 */
class PageContentProposalFactory extends Factory
{
    protected $model = PageContentProposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $markdown = "## قسم محدث\n\n".fake()->paragraph();

        return [
            'page_id' => Page::factory(),
            'corpus_document_id' => CorpusDocument::factory()->ready(),
            'proposed_markdown' => $markdown,
            'proposed_html_content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'قسم محدث']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => fake()->sentence()]]],
                ],
            ],
            'summary' => 'اقتراح تحديث الصفحة من مستند مرفوع.',
            'status' => PageContentProposal::STATUS_PENDING,
            'error' => null,
            'applied_at' => null,
        ];
    }

    /**
     * A proposal that has already been applied to its page.
     */
    public function applied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageContentProposal::STATUS_APPLIED,
            'applied_at' => now(),
        ]);
    }

    /**
     * A proposal the admin declined.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageContentProposal::STATUS_REJECTED,
        ]);
    }
}
