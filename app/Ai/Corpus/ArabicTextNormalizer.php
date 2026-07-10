<?php

namespace App\Ai\Corpus;

use ArPHP\I18N\Arabic;

/**
 * Canonicalizes text for the keyword search leg.
 *
 * The SAME normalization runs at index time (corpus_chunks.normalized_content)
 * and at query time (CorpusRetriever), so "أحكام" matches "احكام" and
 * "مُقَرَّرات" matches "مقررات" regardless of how the author or the searcher
 * typed it. Arabic-specific folding is delegated to ar-php's normalizer
 * (alef/hamza/taa unification, tashkeel + tatweel stripping); Latin text is
 * simply lowercased and whitespace collapsed.
 */
class ArabicTextNormalizer
{
    private ?Arabic $arabic = null;

    public function normalize(string $text): string
    {
        $normalized = $this->arabic()->arNormalizeText($text);

        $normalized = mb_strtolower($normalized);

        $collapsed = preg_replace('/[\s\x{00A0}]+/u', ' ', $normalized);

        return trim($collapsed ?? '');
    }

    /**
     * Split a normalized string into unique search tokens, dropping
     * single-character noise.
     *
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_filter($words, fn (string $word): bool => mb_strlen($word) >= 2);

        return array_values(array_unique($tokens));
    }

    /**
     * Lazily built because the ar-php Arabic object loads locale data; every
     * normalization form is enabled explicitly so behavior never depends on
     * the library's defaults.
     */
    private function arabic(): Arabic
    {
        if ($this->arabic === null) {
            $this->arabic = new Arabic;
            $this->arabic->setNorm('all', true);
        }

        return $this->arabic;
    }
}
