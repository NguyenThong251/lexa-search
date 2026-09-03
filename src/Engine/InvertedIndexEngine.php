<?php

namespace Lexa\Engine;

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Contracts\IndexStore;
use Lexa\Engine\Contracts\Reranker;
use Lexa\Engine\Contracts\SearchEngine;

/**
 * The default engine: an inverted index + BM25F, on top of a swappable
 * IndexStore. Index-time and query-time use the SAME Analyzer instance, so a
 * term indexed and a term queried reduce identically (parity).
 */
final class InvertedIndexEngine implements SearchEngine
{
    private Bm25fScorer $scorer;
    private ?string $lastSuggestion = null;

    public function __construct(
        private IndexStore $store,
        private Analyzer $analyzer,
        private EngineConfig $cfg,
        private ?Reranker $reranker = null
    ) {
        $this->scorer = new Bm25fScorer($cfg);
    }

    public function id(): string { return 'mysql'; }

    /** "Did you mean" suggestion from the last query(), or null. */
    public function lastSuggestion(): ?string { return $this->lastSuggestion; }

    public function index(Document $doc): void
    {
        $this->store->deleteDoc($doc->id);

        $acc      = []; // zone => term => ['kw'=>float, 'pos'=>set]
        $zonePos  = []; // zone => set of positions (for field length)
        $fieldIdx = 0;

        foreach ($doc->fields as $field) {
            $base = $fieldIdx * 1000000; // keep positions distinct across fields
            $res  = $this->analyzer->analyze($field['text'], 'index');
            foreach ($res['postings'] as $p) {
                // analyzer 'text' terms inherit the source zone; code/phrase keep theirs
                $zone = $p['field'] === 'text' ? $field['zone'] : $p['field'];
                $pos  = $base + $p['pos'];
                $kw   = $this->cfg->kindWeight($p['kind']);

                $acc[$zone][$p['term']]['kw']        = max($acc[$zone][$p['term']]['kw'] ?? 0.0, $kw);
                $acc[$zone][$p['term']]['pos'][$pos] = true;
                $zonePos[$zone][$pos]                = true;
            }
            $fieldIdx++;
        }

        $zoneLengths = [];
        foreach ($zonePos as $zone => $positions) {
            $zoneLengths[$zone] = count($positions);
        }

        foreach ($acc as $zone => $terms) {
            foreach ($terms as $term => $info) {
                $this->store->addPosting($doc->id, (string) $term, $zone, $info['kw'], count($info['pos']));
            }
        }

        $this->store->putDoc($doc->id, $zoneLengths);
    }

    public function bulkIndex(array $docs): void
    {
        foreach ($docs as $doc) {
            $this->index($doc);
        }
        $this->flush();
    }

    public function delete(int $docId): void { $this->store->deleteDoc($docId); }

    public function flush(): void { $this->store->flush(); }

    public function query(string $q, int $limit = 20): array
    {
        $res = $this->analyzer->analyze($q, 'query');
        $terms     = [];
        $byPos     = []; // pos => [term => true] — variants of one query word
        foreach ($res['postings'] as $p) {
            $terms[$p['term']]        = true;
            $byPos[$p['pos']][$p['term']] = true;
        }
        $terms = array_keys($terms);
        if (!$terms) {
            return [];
        }

        $n  = $this->store->docCount();
        $df = $this->store->dfForTerms($terms);

        // typo tolerance: expand unknown query words to their nearest indexed
        // term, and build a "did you mean" suggestion. Gated by catalog size.
        $this->lastSuggestion = null;
        if ($this->cfg->typo && $n > 0 && $n <= $this->cfg->typoMaxDocs) {
            [$terms, $byPos, $df, $this->lastSuggestion] = $this->expandTypos($q, $terms, $byPos, $df);
        }

        // each query word (token position) is a group; require a doc to match
        // ALL of them (via any variant, in any field) — precise AND semantics.
        $groups    = array_values(array_map('array_keys', $byPos));
        $minGroups = count($groups);

        $avgdl    = $this->store->avgZoneLengths();
        $postings = $this->store->postingsForTerms($terms);

        $docIds = [];
        foreach ($postings as $p) {
            $docIds[$p['doc_id']] = true;
        }
        $zoneLen = $this->store->zoneLengthsForDocs(array_keys($docIds));

        $scores = $this->scorer->score($terms, $df, $n, $avgdl, $postings, $zoneLen, $groups, $minGroups);

        // Second pass (e.g. freshness). Must run BEFORE the truncation below,
        // or it would only ever reorder the handful of rows a small limit keeps.
        if ($this->reranker !== null && $scores) {
            $scores = $this->reranker->rerank($scores);
        }

        $out = [];
        foreach ($scores as $docId => $score) {
            $out[] = ['doc_id' => (int) $docId, 'score' => round($score, 4)];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function suggest(string $q, int $limit = 10): array
    {
        return $this->query($q, $limit);
    }

    /**
     * Expand unknown query words (likely typos) to their nearest indexed term.
     * @return array{0:string[],1:array,2:array,3:?string} [terms, byPos, df, suggestion]
     */
    private function expandTypos(string $q, array $terms, array $byPos, array $df): array
    {
        $corrections = [];
        $added = false;

        foreach ($byPos as $pos => $tmap) {
            $groupTerms = array_keys($tmap);
            $known = false;
            foreach ($groupTerms as $t) {
                if (($df[$t] ?? 0) > 0) { $known = true; break; }
            }
            if ($known) {
                continue; // word matches the index — not a typo
            }
            // representative = the longest term (the real word, not a fragment)
            $word = '';
            foreach ($groupTerms as $t) {
                if (mb_strlen($t) > mb_strlen($word)) { $word = $t; }
            }
            if (mb_strlen($word) < 3) {
                continue; // too short to correct safely
            }
            $vocab = $this->store->vocabulary(mb_substr($word, 0, 1, 'UTF-8'), $this->cfg->typoVocabCap, $this->cfg->typoMinFreq);
            $best  = FuzzyMatcher::bestMatch($word, $vocab, 2);
            if (!$best || $best['dist'] === 0) {
                continue;
            }
            $auto = FuzzyMatcher::autoThreshold(mb_strlen($word));
            if ($best['dist'] <= $auto) {
                $byPos[$pos][$best['term']] = true; // expand the query — now it can match
                $terms[] = $best['term'];
                $corrections[$word] = $best['term'];
                $added = true;
            } elseif ($best['dist'] <= $auto + 1) {
                $corrections[$word] = $best['term']; // suggest only (too far to auto-correct)
            }
        }

        $suggestion = $corrections ? $this->buildSuggestion($q, $corrections) : null;
        if ($added) {
            $terms = array_values(array_unique($terms));
            $df    = $this->store->dfForTerms($terms);
        }
        return [$terms, $byPos, $df, $suggestion];
    }

    private function buildSuggestion(string $q, array $corrections): ?string
    {
        $s = mb_strtolower($q, 'UTF-8');
        foreach ($corrections as $from => $to) {
            $s = str_replace($from, $to, $s);
        }
        return $s !== mb_strtolower($q, 'UTF-8') ? $s : null;
    }

    public function stats(): array
    {
        return [
            'doc_count'        => $this->store->docCount(),
            'avg_zone_lengths' => $this->store->avgZoneLengths(),
        ];
    }
}
