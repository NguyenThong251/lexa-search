<?php

namespace Lexa\Engine;

use Lexa\Engine\Contracts\Reranker;

/**
 * Freshness pass: pushes recently added (or recently edited) documents towards
 * the top of the results.
 *
 * WordPress-free on purpose, like the rest of Engine/ — the caller injects a
 * resolver that maps doc ids to unix timestamps, so this is unit-testable with
 * no database. Lexa\Wp\EngineManager supplies the wp_posts-backed resolver.
 *
 * Modes:
 *   off                      no change; pure BM25F
 *   light | medium | strong  score + (topScore × strength × freshness) - a capped,
 *                            additive lift, so freshness only reorders near-ties
 *   date                     strict newest-first among the matching documents
 */
final class RecencyReranker implements Reranker
{
    public const MODES = ['off', 'light', 'medium', 'strong', 'date'];

    /**
     * How far a document may climb, as a fraction of the TOP score in the
     * result set. The boost is additive and capped, so a document can only
     * overtake another it is already within this fraction of: freshness
     * reorders near-ties and never overturns a clear relevance winner.
     *
     * It used to be a multiplier (score x (1 + strength x freshness)), which
     * was proportional to the document's own score and so let anything within
     * ~53% of the top take first place on a busy catalogue. That is the wrong
     * trade for a storefront: someone searching a model code wants that model.
     */
    public const STRENGTH = [
        'light'  => 0.04,
        'medium' => 0.10,
        'strong' => 0.25,
    ];

    /**
     * Only the strongest BM25F candidates are re-ranked. A document ranked
     * below this is not relevant enough to surface however new it is, and the
     * cap bounds the resolver to one bulk lookup.
     */
    private const POOL = 500;

    /** @var callable(int[]): array<int,int> */
    private $resolveTimestamps;

    /**
     * @param string $mode one of self::MODES
     * @param int $halfLifeDays days after which the boost has decayed to half
     * @param callable(int[]): array<int,int> $resolveTimestamps docIds => (docId => unix ts)
     */
    public function __construct(
        private string $mode,
        private int $halfLifeDays,
        callable $resolveTimestamps
    ) {
        if (!in_array($this->mode, self::MODES, true)) {
            $this->mode = 'off';
        }
        $this->halfLifeDays      = max(1, $this->halfLifeDays);
        $this->resolveTimestamps = $resolveTimestamps;
    }

    public function rerank(array $scores, string $query = ''): array
    {
        if ($this->mode === 'off' || !$scores) {
            return $scores;
        }

        $pool = array_slice($scores, 0, self::POOL, true);
        $tail = array_slice($scores, self::POOL, null, true);

        $times = ($this->resolveTimestamps)(array_keys($pool));
        if (!$times) {
            return $scores; // no usable dates — leave BM25F alone
        }

        return ($this->mode === 'date'
            ? $this->sortByDate($pool, $times)
            : $this->boost($pool, $times)) + $tail;
    }

    /**
     * Strict newest-first. Documents with no usable date sort last; ties fall
     * back to the BM25F score, so the order stays deterministic.
     *
     * @param array<int,float> $pool
     * @param array<int,int> $times
     * @return array<int,float>
     */
    private function sortByDate(array $pool, array $times): array
    {
        $ids    = array_keys($pool);
        $stamps = [];
        $vals   = [];
        foreach ($ids as $id) {
            $stamps[] = $times[$id] ?? 0;
            $vals[]   = $pool[$id];
        }
        array_multisort($stamps, SORT_DESC, SORT_NUMERIC, $vals, SORT_DESC, SORT_NUMERIC, $ids);

        $sorted = [];
        foreach ($ids as $i => $id) {
            $sorted[$id] = $vals[$i];
        }
        return $sorted;
    }

    /**
     * @param array<int,float> $pool
     * @param array<int,int> $times
     * @return array<int,float>
     */
    private function boost(array $pool, array $times): array
    {
        $strength = self::STRENGTH[$this->mode] ?? 0.10;
        $halfLife = $this->halfLifeDays * 86400;
        $now      = time();
        // The climb is capped against the BEST score in the set, not against a
        // document's own score, so every document has the same maximum lift and
        // the worst case is knowable: nothing more than $strength below the top
        // can reach first place.
        $budget = max($pool) * $strength;
        if ($budget <= 0) {
            return $pool;
        }

        foreach ($pool as $id => $score) {
            $ts = $times[$id] ?? 0;
            if ($ts <= 0) {
                continue; // unknown date - no boost, no penalty
            }
            // Exponential decay: 1.0 for something posted right now, 0.5 at one
            // half-life, tending to 0 for old stock.
            $freshness = exp(-M_LN2 * max(0, $now - $ts) / $halfLife);
            $pool[$id] = $score + $budget * $freshness;
        }

        arsort($pool);
        return $pool;
    }
}
