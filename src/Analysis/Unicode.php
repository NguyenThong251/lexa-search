<?php

namespace Lexa\Analysis;

/**
 * Pure, mbstring-only codepoint helpers. No ext-intl required.
 */
final class Unicode
{
    /** @return int[] list of Unicode codepoints */
    public static function codepoints(string $s): array
    {
        $cps = [];
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $cps[] = mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
        }
        return $cps;
    }

    /** Vietnamese tone/modifier marks + general combining diacritics. */
    public static function isCombiningMark(int $cp): bool
    {
        return ($cp >= 0x0300 && $cp <= 0x036F) // combining diacritical marks (tones, circumflex, breve)
            || $cp === 0x031B;                  // combining horn (ơ, ư) — already in range, kept explicit
    }

    public static function isCJK(int $cp): bool
    {
        return ($cp >= 0x4E00 && $cp <= 0x9FFF)   // CJK Unified Ideographs
            || ($cp >= 0x3040 && $cp <= 0x30FF)   // Hiragana + Katakana
            || ($cp >= 0xAC00 && $cp <= 0xD7A3);  // Hangul syllables
    }

    public static function isAsciiLetter(int $cp): bool
    {
        return ($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A);
    }

    public static function isDigit(int $cp): bool
    {
        return $cp >= 0x30 && $cp <= 0x39;
    }
}
