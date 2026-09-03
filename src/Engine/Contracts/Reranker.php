<?php

namespace Lexa\Engine\Contracts;

/**
 * An optional second ranking pass, applied to the BM25F scores BEFORE the
 * result list is truncated to the caller's limit. Truncating first would make
 * the re-rank a no-op for small limits (autocomplete asks for 10), so order of
 * operations matters here.
 *
 * Kept WP-free like the rest of Engine/: an implementation receives nothing but
 * scores and returns scores. The WordPress-specific source of truth (post
 * dates, popularity, stock, …) lives in the Wp/ layer.
 */
interface Reranker
{
    /**
     * @param array<int,float> $scores docId => score, sorted descending
     * @return array<int,float> docId => score, sorted descending
     */
    public function rerank(array $scores): array;
}
