<?php

namespace Lexa\Analysis;

/**
 * Diacritic folding — pure, mbstring-only, intl-free.
 *
 * Handles BOTH precomposed (NFC) and decomposed (NFD) input:
 *  - precomposed Vietnamese vowels/đ are mapped directly to a base ASCII letter;
 *  - standalone combining marks (tones, circumflex, breve, horn) are stripped,
 *    so decomposed input converges to the same result even where ext-intl /
 *    Normalizer is unavailable (the verified differentiator vs WP core).
 */
final class Folder
{
    public const VERSION = 'fold-1';

    private static ?array $viMap = null;
    private static ?array $latinMap = null;

    private static function combiningMarks(): array
    {
        $marks = [];
        // grave, acute, circumflex, tilde, breve, hook-above, horn, dot-below
        foreach ([0x0300, 0x0301, 0x0302, 0x0303, 0x0306, 0x0309, 0x031B, 0x0323] as $cp) {
            $marks[mb_chr($cp, 'UTF-8')] = '';
        }
        return $marks;
    }

    private static function viMap(): array
    {
        if (self::$viMap !== null) {
            return self::$viMap;
        }
        $groups = [
            'a' => 'áàảãạăắằẳẵặâấầẩẫậ',
            'e' => 'éèẻẽẹêếềểễệ',
            'i' => 'íìỉĩị',
            'o' => 'óòỏõọôốồổỗộơớờởỡợ',
            'u' => 'úùủũụưứừửữự',
            'y' => 'ýỳỷỹỵ',
            'd' => 'đ',
        ];
        $map = [];
        foreach ($groups as $base => $chars) {
            foreach (mb_str_split($chars, 1, 'UTF-8') as $ch) {
                $map[$ch] = $base;
            }
        }
        return self::$viMap = array_merge($map, self::combiningMarks());
    }

    private static function latinMap(): array
    {
        if (self::$latinMap !== null) {
            return self::$latinMap;
        }
        // Common non-Vietnamese Latin diacritics, so the generic pack folds them too.
        $extra = [
            'ñ' => 'n', 'ü' => 'u', 'ö' => 'o', 'ä' => 'a', 'ß' => 'ss', 'ç' => 'c',
            'ø' => 'o', 'å' => 'a', 'î' => 'i', 'û' => 'u', 'ë' => 'e', 'ï' => 'i', 'œ' => 'oe',
        ];
        return self::$latinMap = array_merge(self::viMap(), $extra);
    }

    /** Vietnamese-aware fold: "Máy Cưa" -> "may cua". */
    public static function vietnamese(string $term): string
    {
        return strtr(mb_strtolower($term, 'UTF-8'), self::viMap());
    }

    /** Broader Latin fold for the generic pack. */
    public static function latin(string $term): string
    {
        return strtr(mb_strtolower($term, 'UTF-8'), self::latinMap());
    }
}
