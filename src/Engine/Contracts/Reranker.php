<?php

namespace Lexa\Engine\Contracts;

/**
 * An optional second ranking pass, applied to the BM25F scores BEFORE the
 * result list is truncated to the caller's limit. Truncating first would make
 * the re-rank a no-op for small limits (autocomplete asks for 10), so order of
 * operations matters here.
 *
 * Kept WP-free like the rest of Engine/: an implementation receives scores and
 * the raw query, and returns scores. The WordPress-specific sources of truth
 * (post dates, titles, popularity, stock, …) are injected as resolvers.
 */
interface Reranker
{
    /**
     * @param array<int,float> $scores docId => score, sorted descending
     * @param string $query the raw query string the user typed
     * @return array<int,float> docId => score, sorted descending
     */
    public function rerank(array $scores, string $query): array;
}
