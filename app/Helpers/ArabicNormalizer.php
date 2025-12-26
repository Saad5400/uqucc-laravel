<?php

namespace App\Helpers;

class ArabicNormalizer
{
    /**
     * Normalize Arabic text for comparison.
     * - Removes diacritics (tashkeel)
     * - Normalizes all hamza forms to alef
     * - Normalizes alef maqsura to ya
     * - Normalizes ta marbuta to ha
     * - Converts to lowercase
     * - Trims whitespace
     */
    public static function normalize(string $text): string
    {
        // Convert to lowercase first
        $text = mb_strtolower($text, 'UTF-8');

        // Remove diacritics (tashkeel)
        $text = self::removeDiacritics($text);

        // Normalize hamza forms
        $text = self::normalizeHamza($text);

        // Normalize alef maqsura to ya
        $text = str_replace('ى', 'ي', $text);

        // Normalize ta marbuta to ha (for better matching)
        $text = str_replace('ة', 'ه', $text);

        // Trim whitespace
        $text = trim($text);

        return $text;
    }

    /**
     * Normalize Arabic text and remove the definite article ال (al-).
     * This allows matching with or without the article.
     */
    public static function normalizeWithoutDefiniteArticle(string $text): string
    {
        $normalized = self::normalize($text);

        // Remove the definite article ال from the beginning
        // After normalization, it will be "ال" (alef + lam)
        if (mb_substr($normalized, 0, 2, 'UTF-8') === 'ال') {
            $normalized = mb_substr($normalized, 2, null, 'UTF-8');
        }

        return $normalized;
    }

    /**
     * Remove Arabic diacritics (tashkeel).
     */
    protected static function removeDiacritics(string $text): string
    {
        $diacritics = [
            'َ', // Fatha
            'ً', // Tanween Fath
            'ُ', // Damma
            'ٌ', // Tanween Damm
            'ِ', // Kasra
            'ٍ', // Tanween Kasr
            'ْ', // Sukun
            'ّ', // Shadda
            'ـ', // Tatweel
        ];

        return str_replace($diacritics, '', $text);
    }

    /**
     * Normalize all hamza forms to plain alef.
     */
    protected static function normalizeHamza(string $text): string
    {
        // All hamza on alef forms -> plain alef
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);

        // Hamza on waw -> waw
        $text = str_replace('ؤ', 'و', $text);

        // Hamza on ya -> ya
        $text = str_replace('ئ', 'ي', $text);

        // Standalone hamza -> alef (for simplicity in matching)
        $text = str_replace('ء', 'ا', $text);

        return $text;
    }

    /**
     * Check if two Arabic texts match when normalized.
     */
    public static function matches(string $text1, string $text2): bool
    {
        return self::normalize($text1) === self::normalize($text2);
    }

    /**
     * Check if two Arabic texts match when normalized and ignoring ال.
     */
    public static function matchesWithoutDefiniteArticle(string $text1, string $text2): bool
    {
        return self::normalizeWithoutDefiniteArticle($text1) === self::normalizeWithoutDefiniteArticle($text2);
    }
}
