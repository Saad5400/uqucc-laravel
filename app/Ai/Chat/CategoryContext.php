<?php

namespace App\Ai\Chat;

use App\Ai\Corpus\CorpusResultFormatter;
use App\Ai\Corpus\CorpusRetriever;
use App\Models\Corpus\CorpusDocument;
use App\Models\Corpus\CorpusItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Hands the model the evidence a categorized turn needs BEFORE it answers,
 * instead of hoping it goes and fetches that evidence itself.
 *
 * The failure this exists for: asked to compare majors, the model answered
 * from memory without calling a single tool — naming a major this college does
 * not offer and attributing the invention to documents it never opened. A rule
 * telling it to search cannot fix that, because obeying the rule is exactly
 * the step it skipped. So the search runs here, in PHP, and its results are in
 * context before the model's first token: the index of every readable document
 * (so an unread major cannot be invented, and get_document ids are exact), the
 * retrieval hits for this question, and the category's own answering guidance.
 *
 * Like {@see AttachmentContext} and {@see TelegramTurnContext}, the block goes
 * in the TURN TAIL — the current user message, after the cached system prompt,
 * tools and history — so prefix caching is never disturbed, and an uncategor-
 * ized message passes through byte-identical. It composes as the outer wrapper
 * around AttachmentContext, so unwrapping runs outside-in.
 *
 * Guidance injected here is HOW to answer this topic well. Rules that must
 * hold on every turn live in the agent instructions, where a classifier miss
 * cannot reach them.
 */
class CategoryContext
{
    /** First line of every wrapped prompt — the unwrap guard. */
    private const SENTINEL = '[مصادر جاهزة من الدليل — جُهّزت آلياً حسب موضوع السؤال، للقراءة فقط]';

    /** Marker separating the evidence blocks from the visitor's own message. */
    private const MESSAGE_MARKER = 'رسالة المستخدم:';

    /** Retrieval hits injected per turn — enough to ground, short enough to read. */
    private const SNIPPET_LIMIT = 6;

    /** Ceiling on the document index, so a growing corpus cannot flood a turn. */
    private const DOCUMENT_LIMIT = 40;

    public function __construct(
        private readonly QuestionClassifier $classifier,
        private readonly CorpusRetriever $retriever,
        private readonly CorpusResultFormatter $formatter,
    ) {}

    /**
     * Wrap an outgoing prompt with the evidence for its category. $question is
     * the visitor's raw text (what to classify and retrieve on); $prompt is
     * what actually goes to the model, which inner wrappers may already have
     * decorated. An uncategorized turn is returned untouched.
     */
    public function wrap(string $prompt, string $question): string
    {
        $category = $this->classifier->classify($question);

        if ($category === null) {
            return $prompt;
        }

        $blocks = array_filter([
            $category->intro(),
            $this->documentIndex(),
            $this->snippets($question),
            $category->directives(),
        ], static fn (string $block): bool => $block !== '');

        return self::SENTINEL."\n".implode("\n\n", $blocks)
            ."\n\n".self::MESSAGE_MARKER."\n".$prompt;
    }

    /**
     * Return the wrapped prompt from a stored (possibly wrapped) user message.
     * Content this class did not produce passes through.
     */
    public function unwrap(string $content): string
    {
        if (! str_starts_with($content, self::SENTINEL)) {
            return $content;
        }

        $marker = self::MESSAGE_MARKER."\n";

        if (! str_contains($content, $marker)) {
            return $content;
        }

        return Str::after($content, $marker);
    }

    /**
     * Every document the assistant may actually read, by id and citation URL.
     *
     * Titles alone do most of the work: a major with no document here is one
     * the model has no source for, and the id spares it from guessing which
     * get_document call to make.
     */
    private function documentIndex(): string
    {
        $documents = CorpusDocument::query()
            ->where('status', CorpusDocument::STATUS_READY)
            ->whereHas('corpusItem', fn (Builder $item): Builder => $item
                ->where('status', CorpusItem::STATUS_READY)
                ->where('enabled', true))
            ->orderBy('id')
            ->limit(self::DOCUMENT_LIMIT)
            ->get();

        if ($documents->isEmpty()) {
            return '';
        }

        $lines = $documents
            ->map(fn (CorpusDocument $document): string => sprintf(
                '- المستند %d: %s — %s',
                $document->id,
                $document->title,
                $document->referenceUrl(),
            ))
            ->implode("\n");

        return "فهرس المستندات المتاحة للقراءة (وهي كل ما لدى الدليل من مستندات؛ اقرأ أيّها بأداة get_document برقمه):\n".$lines;
    }

    /**
     * The retrieval the model would have run — already run, in the same shape
     * search_content returns, so a handed snippet reads exactly like a fetched
     * one and gets cited the same way.
     */
    private function snippets(string $question): string
    {
        $results = $this->retriever->search($question, self::SNIPPET_LIMIT, includeHidden: true);

        if ($results->isEmpty()) {
            return '';
        }

        return "مقتطفات من محتوى الدليل ذات صلة بالسؤال:\n\n".$this->formatter->render($results);
    }
}
