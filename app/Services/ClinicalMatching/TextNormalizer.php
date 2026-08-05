<?php

namespace App\Services\ClinicalMatching;

class TextNormalizer
{
    /**
     * Normalize free text for catalog matching: lowercase, strip accents,
     * drop special characters, collapse whitespace.
     */
    public function normalize(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = $this->removeAccents($value);
        $value = preg_replace('/[^a-z0-9\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Expand known clinical abbreviations after normalization.
     *
     * @param  array<string, string>  $abbreviations
     */
    public function expandAbbreviations(string $normalized, array $abbreviations): string
    {
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $expanded = array_map(function (string $token) use ($abbreviations) {
            return $abbreviations[$token] ?? $token;
        }, $tokens);

        return implode(' ', $expanded);
    }

    public function removeAccents(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($transliterated === false) {
            return $value;
        }

        return $transliterated;
    }
}
