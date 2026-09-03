<?php

namespace Lexa\Engine\Contracts;

/**
 * Persistence abstraction for the inverted index. Swappable: an in-memory
 * array store (for fast unit tests of the scorer) and a MySQL store (real).
 * The engine and scorer are storage-agnostic.
 *
 * A "zone" is a weighting bucket (title, sku, content, code, phrase). A
 * "posting" links a term to a doc in a zone, carrying its best kind-weight and
 * term frequency (number of token positions).
 */
interface IndexStore
{
    /** @param array<string,int> $zoneLengths zone => token count */
    public function putDoc(int $docId, array $zoneLengths): void;

    public function addPosting(int $docId, string $term, string $zone, float $kw, int $tf): void;

    public function deleteDoc(int $docId): void;

    public function flush(): void;

    public function docCount(): int;

    /** @return array<string,float> zone => average length across the corpus */
    public function avgZoneLengths(): array;

    /**
     * @param string[] $terms
     * @return array<string,int> term => number of distinct docs containing it
     */
    public function dfForTerms(array $terms): array;

    /**
     * @param string[] $terms
     * @return array<int,array{doc_id:int,term:string,zone:string,kw:float,tf:int}>
     */
    public function postingsForTerms(array $terms): array;

    /**
     * @param int[] $docIds
     * @return array<int,array<string,int>> docId => (zone => length)
     */
    public function zoneLengthsForDocs(array $docIds): array;

    /**
     * Indexed terms beginning with $firstChar that appear in >= $minFreq docs,
     * ordered by frequency desc (for typo correction — common terms first, junk
     * one-offs excluded). @return string[]
     */
    public function vocabulary(string $firstChar, int $limit, int $minFreq = 2): array;
}
