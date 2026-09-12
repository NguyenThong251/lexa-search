<?php

namespace Lexa\Engine;

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Contracts\Reranker;

/**
 * Promotes documents that actually contain what the user typed, in their title.
 *
 * BM25F has no notion of adjacency: it scores each query word independently, so
 * a product whose title IS "… – SMQH 1200 4" can lose to products that merely
 * mention "smqh", "1200" and "4" in separate sentences of their description —
 * especially since BM25F's length normalization penalises the longer, more
 * specific title. On a catalogue where descriptions cross-reference other
 * machines' model codes, that happens constantly.
 *
 * This pass re-sorts by a coarse tier first and leaves the incoming order
 * (relevance, already freshness-adjusted) to break ties inside a tier:
 *
 *   3  the title contains the whole query as a consecutive run of tokens
 *   2  the title contains every query token, in any order
 *   1  the title contains some query tokens
 *   0  the title contains none — the document matched only via description,
 *      SKU or category
 *
 * Tokenisation goes through the same Analyzer used to build the index, so
 * "may cua" matches "Máy Cưa" exactly as it does at query time.
 */
final class ExactMatchReranker implements Reranker
{
    /**
     * Must be at least RecencyReranker's pool. That pass runs first and can
     * move a document down the list; anything it pushes past this cap would
     * land in the untiered tail and silently lose its promotion. Titles are
     * analysed per search, which is the real cost here (~10ms for 500).
     */
    private const POOL = 500;

    /** @var callable(int[]): array<int,string> */
    private $resolveTitles;

    /** @param callable(int[]): array<int,string> $resolveTitles docIds => (docId => title) */
    public function __construct(private Analyzer $analyzer, callable $resolveTitles)
    {
        $this->resolveTitles = $resolveTitles;
    }

    public function rerank(array $scores, string $query): array
    {
        if (count($scores) < 2) {
            return $scores;
        }

        $queryTokens = $this->tokens($query, 'query');
        if (!$queryTokens) {
            return $scores;
        }

        $pool = array_slice($scores, 0, self::POOL, true);
        $tail = array_slice($scores, self::POOL, null, true);

        $titles = ($this->resolveTitles)(array_keys($pool));
        if (!$titles) {
            return $scores;
        }

        // Bucket by tier, preserving the incoming order INSIDE each bucket. That
        // order already carries relevance and, in "newest first" mode, the date
        // ordering the freshness pass produced; an arsort() here would re-sort
        // by BM25F value and throw that away, silently reducing "newest first"
        // to "off".
        $buckets = [3 => [], 2 => [], 1 => [], 0 => []];
        foreach ($pool as $id => $score) {
            $buckets[$this->tier($queryTokens, $titles[$id] ?? '')][$id] = $score;
        }

        // Offset each tier by more than any score, so the published score agrees
        // with the rank wherever the incoming list was itself score-ordered.
        $step = (max($pool) ?: 1.0) + 1.0;

        $out = [];
        foreach ([3, 2, 1, 0] as $tier) {
            foreach ($buckets[$tier] as $id => $score) {
                $out[$id] = $score + $tier * $step;
            }
        }

        return $out + $tail;
    }

    /**
     * @param string[] $queryTokens
     */
    private function tier(array $queryTokens, string $title): int
    {
        if ($title === '') {
            return 0;
        }
        $titleTokens = $this->tokens($title, 'index');
        if (!$titleTokens) {
            return 0;
        }

        if ($this->containsRun($titleTokens, $queryTokens)) {
            return 3;
        }

        $present = 0;
        foreach ($queryTokens as $t) {
            if (in_array($t, $titleTokens, true)) {
                $present++;
            }
        }
        if ($present === count($queryTokens)) {
            return 2;
        }
        return $present > 0 ? 1 : 0;
    }

    /**
     * @param string[] $haystack
     * @param string[] $needle
     */
    private function containsRun(array $haystack, array $needle): bool
    {
        $n = count($needle);
        $h = count($haystack);
        if ($n === 0 || $n > $h) {
            return false;
        }
        for ($i = 0; $i <= $h - $n; $i++) {
            $ok = true;
            for ($j = 0; $j < $n; $j++) {
                if ($haystack[$i + $j] !== $needle[$j]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /**
     * The base token at each position, in order. Variants (prefix, bigram,
     * stem, fold) are deliberately dropped: this pass asks "did the user's
     * words literally appear here", which is the signal BM25F is missing.
     *
     * @return string[]
     */
    private function tokens(string $text, string $mode): array
    {
        if (trim($text) === '') {
            return [];
        }
        // One canonical term per position, preferring the diacritic-folded form
        // so "may cua" and "MÁY CƯA" compare equal — the analyzer only emits a
        // 'fold' term when folding actually changed the word. Every other kind
        // (bigram, prefix, stem, code variants) is deliberately ignored: this
        // pass asks whether the user's words literally appear, which is exactly
        // the signal BM25F lacks.
        $lower = [];
        $fold  = [];
        foreach ($this->analyzer->analyze($text, $mode)['postings'] as $p) {
            if ($p['kind'] === 'fold') {
                $fold[$p['pos']] ??= $p['term'];
            } elseif ($p['kind'] === 'lower') {
                $lower[$p['pos']] ??= $p['term'];
            }
        }
        $byPos = $lower;
        foreach ($fold as $pos => $term) {
            $byPos[$pos] = $term;
        }
        ksort($byPos);
        return array_values($byPos);
    }
}
