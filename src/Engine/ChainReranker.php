<?php

namespace Lexa\Engine;

use Lexa\Engine\Contracts\Reranker;

/**
 * Runs several re-rank passes in order. The LAST pass has the final say, so
 * order encodes priority: the freshness pass runs first and the exact-match
 * pass runs after it, which is what makes "the product you actually named
 * comes first, newer suggestions after" hold.
 */
final class ChainReranker implements Reranker
{
    /** @var Reranker[] */
    private array $passes;

    public function __construct(Reranker ...$passes)
    {
        $this->passes = $passes;
    }

    public function rerank(array $scores, string $query): array
    {
        foreach ($this->passes as $pass) {
            $scores = $pass->rerank($scores, $query);
        }
        return $scores;
    }
}
