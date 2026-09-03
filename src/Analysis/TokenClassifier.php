<?php

namespace Lexa\Analysis;

/**
 * Classifies a single token by character class. Runs ONCE per token,
 * before any language pack. This is the only routing authority — a pack
 * may never claim a class it must not touch (so it can't corrupt a brand
 * like "Makita" or a code like "HS7601").
 */
final class TokenClassifier
{
    public function classify(string $surface): string
    {
        $hasDigit = $hasAscii = $hasMarked = $hasCJK = false;

        foreach (Unicode::codepoints($surface) as $cp) {
            if (Unicode::isDigit($cp)) {
                $hasDigit = true;
            } elseif (Unicode::isAsciiLetter($cp)) {
                $hasAscii = true;
            } elseif (Unicode::isCombiningMark($cp)) {
                $hasMarked = true;
            } elseif (Unicode::isCJK($cp)) {
                $hasCJK = true;
            } elseif ($cp > 0x7F) {
                // Non-ASCII letter (Vietnamese precomposed vowel, đ, or other diacritic Latin).
                $hasMarked = true;
            }
            // else: ASCII punctuation kept inside a token ( - _ . / ) — ignored for class.
        }

        $hasLetter = $hasAscii || $hasMarked;

        if ($hasCJK) {
            return TokenClass::CJK;
        }
        if ($hasDigit && $hasLetter) {
            // digits followed by a short letter unit => NUMERIC_UNIT (220V, 3200mm);
            // any other letter+digit mix => ALNUM_CODE (HS7601, AKV3005DK-F).
            return preg_match('/^[0-9]+[a-zA-Z]{1,3}$/', $surface)
                ? TokenClass::NUMERIC_UNIT
                : TokenClass::ALNUM_CODE;
        }
        if ($hasDigit) {
            return TokenClass::NUMERIC_UNIT; // pure number (7601)
        }
        if ($hasMarked) {
            return TokenClass::WORD_DIACRITIC;
        }
        if ($hasAscii) {
            return TokenClass::WORD_LATIN;
        }
        return TokenClass::OTHER;
    }
}
