<?php

namespace Lexa\Engine;

/**
 * Bounded Damerau-Levenshtein fuzzy matching for typo tolerance. Operates in
 * the folded term space (both query and index terms are diacritic-folded), so
 * "cuaa"/"cwa"/"cau" all resolve to "cua". Thresholds are length-aware:
 * short words allow 1 edit, longer allow 2 — short + 2 edits is too risky
 * (false positives), so those become "did you mean" suggestions, not auto-matches.
 */
final class FuzzyMatcher
{
    /** Max edits to AUTO-correct (expand the query). */
    public static function autoThreshold(int $len): int
    {
        return $len <= 4 ? 1 : 2;
    }

    /**
     * Does this term look like a product / model code rather than a word?
     *
     * Codes must never be auto-corrected: rewriting one does not fix a spelling,
     * it asks for a different product. A real case from the catalogue —
     * "SM 2000 DSSBD" had DSSBD silently rewritten to "dsb", which matched a
     * single unrelated machine, and WooCommerce then redirected the customer
     * straight into it.
     *
     * The token classifier only flags ALNUM_CODE for letters+digits ("HS7601"),
     * so purely alphabetic codes — DSSBD, SMQH, KW, SM — arrive here looking
     * like ordinary Latin words. Vietnamese and English words always carry a
     * vowel; these consonant runs do not, which separates them cleanly.
     */
    public static function isCodeLike(string $term): bool
    {
        if ($term === '') {
            return false;
        }
        if (preg_match('/\d/', $term)) {
            return true; // any digit => a code, not a word
        }
        // ASCII-only and vowel-less => a consonant run, i.e. an abbreviation.
        return (bool) preg_match('/^[a-z]+$/', $term) && !preg_match('/[aeiouy]/', $term);
    }

    /**
     * Is $candidate a plausible correction of $word, beyond raw edit distance?
     *
     * A correction that also changes the LENGTH by more than one is usually a
     * different word rather than a typo ("dssbd" -> "dsb" is two edits and two
     * characters shorter). Genuine typos — doubled or dropped letters,
     * transpositions — stay within one.
     */
    public static function isPlausible(string $word, string $candidate): bool
    {
        return abs(mb_strlen($word) - mb_strlen($candidate)) <= 1;
    }

    /** Bounded Damerau-Levenshtein (optimal string alignment). Returns dist or $max+1. */
    public static function distance(string $a, string $b, int $max): int
    {
        $aa = mb_str_split($a, 1, 'UTF-8');
        $bb = mb_str_split($b, 1, 'UTF-8');
        $la = count($aa);
        $lb = count($bb);
        if (abs($la - $lb) > $max) {
            return $max + 1;
        }
        $prev2 = null;
        $prev  = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $aa[$i - 1] === $bb[$j - 1] ? 0 : 1;
                $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($prev2 !== null && $i > 1 && $j > 1 && $aa[$i - 1] === $bb[$j - 2] && $aa[$i - 2] === $bb[$j - 1]) {
                    $v = min($v, $prev2[$j - 2] + 1); // transposition (cua <-> cau)
                }
                $cur[$j] = $v;
                if ($v < $rowMin) {
                    $rowMin = $v;
                }
            }
            if ($rowMin > $max) {
                return $max + 1; // whole row already exceeds the bound
            }
            $prev2 = $prev;
            $prev  = $cur;
        }
        return $prev[$lb] <= $max ? $prev[$lb] : $max + 1;
    }

    /**
     * Closest vocabulary term to $word within $max edits. $vocab should be
     * ordered most-frequent-first, so on a distance tie the more frequent (and
     * thus safer) term wins.
     * @param string[] $vocab
     * @return array{term:string,dist:int}|null
     */
    public static function bestMatch(string $word, array $vocab, int $max = 2): ?array
    {
        $best = null;
        foreach ($vocab as $term) {
            if ($term === $word) {
                return ['term' => $term, 'dist' => 0];
            }
            $d = self::distance($word, $term, $max);
            if ($d > $max) {
                continue;
            }
            if ($best === null || $d < $best['dist']) { // strict: keep the earlier (more frequent) on ties
                $best = ['term' => $term, 'dist' => $d];
                if ($d === 1) {
                    break; // distance-1 against a frequent term is good enough — stop early
                }
            }
        }
        return $best;
    }
}
