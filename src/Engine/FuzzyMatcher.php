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
