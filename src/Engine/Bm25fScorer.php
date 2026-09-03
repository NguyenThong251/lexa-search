<?php

namespace Lexa\Engine;

/**
 * BM25F: per-zone length normalization and field weighting are applied BEFORE
 * the term-frequency saturation (Robertson's field-weighting model, the same
 * shape Lucene uses). Per-kind weights let exact/fold full-token matches
 * dominate prefix/bigram/stem matches. IDF is per query term (distinct-doc
 * frequency), so fold/variant postings of OTHER tokens never dilute it.
 */
final class Bm25fScorer
{
    public function __construct(private EngineConfig $cfg) {}

    /**
     * @param string[] $terms distinct query terms
     * @param array<string,int> $df term => doc frequency
     * @param array<string,float> $avgdl zone => average length
     * @param array<int,array{doc_id:int,term:string,zone:string,kw:float,tf:int}> $postings
     * @param array<int,array<string,int>> $zoneLen docId => (zone => length)
     * @param array<int,string[]> $groups query terms grouped by source token (variants of one word)
     * @param int $minGroups a doc must cover at least this many groups (AND over the user's words)
     * @return array<int,float> docId => score, sorted desc
     */
    public function score(array $terms, array $df, int $N, array $avgdl, array $postings, array $zoneLen, array $groups = [], int $minGroups = 0): array
    {
        $idf = [];
        foreach ($terms as $t) {
            $d = $df[$t] ?? 0;
            $idf[$t] = $d > 0 ? log(1 + ($N - $d + 0.5) / ($d + 0.5)) : 0.0;
        }

        // group postings: doc => term => [rows]
        $byDoc = [];
        foreach ($postings as $p) {
            $byDoc[$p['doc_id']][$p['term']][] = $p;
        }

        $scores = [];
        foreach ($byDoc as $docId => $termRows) {
            // min-should-match: require the doc to cover >= minGroups of the
            // query words (each word may match via any of its variants / fields).
            if ($minGroups > 0 && $groups) {
                $covered = 0;
                foreach ($groups as $group) {
                    foreach ($group as $t) {
                        if (isset($termRows[$t])) { $covered++; break; }
                    }
                }
                if ($covered < $minGroups) {
                    continue;
                }
            }

            $s = 0.0;
            foreach ($termRows as $term => $rows) {
                if (($idf[$term] ?? 0) <= 0) {
                    continue;
                }
                $wtf = 0.0;
                foreach ($rows as $r) {
                    $w   = $this->cfg->zoneWeight($r['zone']);
                    $dl  = $zoneLen[$docId][$r['zone']] ?? 0;
                    $avg = $avgdl[$r['zone']] ?? 0.0;
                    $norm = $avg > 0 ? (1 - $this->cfg->b + $this->cfg->b * ($dl / $avg)) : 1.0;
                    if ($norm <= 0) {
                        $norm = 1.0;
                    }
                    $wtf += $w * $r['kw'] * $r['tf'] / $norm;
                }
                if ($wtf > 0) {
                    $s += $idf[$term] * ($wtf / ($this->cfg->k1 + $wtf));
                }
            }
            if ($s > 0) {
                $scores[$docId] = $s;
            }
        }

        arsort($scores);
        return $scores;
    }
}
