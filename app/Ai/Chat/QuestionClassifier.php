<?php

namespace App\Ai\Chat;

use App\Ai\Corpus\ArabicTextNormalizer;

/**
 * Puts a turn's question into a {@see QuestionCategory}, or none.
 *
 * Keyword matching over the SAME Arabic normalization the corpus index uses,
 * so "التخصصات" and "التخصّصات" both hit "تخصص". Deliberately not a model
 * call: this runs on every turn before the model does, and an extra round trip
 * would cost more latency than the category block saves in tokens. Missing a
 * category only means the turn behaves exactly as it did before categories
 * existed — never that a rule is skipped.
 *
 * Follow-ups need no stickiness of their own: the wrapped prompt is what the
 * conversation store persists, so an earlier turn's evidence block is still in
 * the replayed history when the student asks "وأيهما أنسب لي؟" next.
 */
class QuestionClassifier
{
    public function __construct(private readonly ArabicTextNormalizer $normalizer) {}

    /**
     * The first category whose keywords appear in the question.
     */
    public function classify(string $question): ?QuestionCategory
    {
        $normalized = $this->normalizer->normalize($question);

        if ($normalized === '') {
            return null;
        }

        foreach (QuestionCategory::cases() as $category) {
            foreach ($category->keywords() as $keyword) {
                if ($this->matches($normalized, $this->normalizer->normalize($keyword))) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Arabic keywords match as substrings — the language glues prefixes and
     * suffixes onto the stem ("بتخصصي"). Latin keywords match whole words
     * only, so "major" does not fire on "majority".
     */
    private function matches(string $normalized, string $keyword): bool
    {
        if ($keyword === '') {
            return false;
        }

        if (preg_match('/^[\x20-\x7E]+$/', $keyword) === 1) {
            return preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $normalized) === 1;
        }

        return str_contains($normalized, $keyword);
    }
}
