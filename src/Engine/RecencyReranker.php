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
 *   light | medium | strong  score × (1 + strength × freshness), so relevance
 *                            still decides between a good and a poor match
 *   date                     strict newest-first among the matching documents
 */
final class RecencyReranker implements Reranker
{
    public const MODES = ['off', 'light', 'medium', 'strong', 'date'];

    /** Boost strength per mode. 'off' and 'date' do not use it. */
    public const STRENGTH = [
        'light'  => 0.35,
        'medium' => 0.9,
        'strong' => 2.5,
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

    public function rerank(array $scores): array
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
        $strength = self::STRENGTH[$this->mode] ?? 0.9;
        $halfLife = $this->halfLifeDays * 86400;
        $now      = time();

        foreach ($pool as $id => $score) {
            $ts = $times[$id] ?? 0;
            if ($ts <= 0) {
                continue; // unknown date — no boost, no penalty
            }
            // Exponential decay: 1.0 for something posted right now, 0.5 at one
            // half-life, tending to 0 for old stock. Bounded, so at the lower
            // strengths a brand-new weak match cannot overtake a much stronger
            // old one — the reason this is not simply a date sort.
            $freshness = exp(-M_LN2 * max(0, $now - $ts) / $halfLife);
            $pool[$id] = $score * (1.0 + $strength * $freshness);
        }

        arsort($pool);
        return $pool;
    }
}
