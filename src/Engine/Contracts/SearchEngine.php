<?php

namespace Lexa\Engine\Contracts;

use Lexa\Engine\Document;

/**
 * The one interface every backend implements (MySQL inverted-index now;
 * Meilisearch/Typesense/ES later). The core indexing & query layers only ever
 * call this — switching engine never touches the pipeline.
 */
interface SearchEngine
{
    public function id(): string;

    public function index(Document $doc): void;

    /** @param Document[] $docs */
    public function bulkIndex(array $docs): void;

    public function delete(int $docId): void;

    /** @return array<int,array{doc_id:int,score:float}> ranked, best first */
    public function query(string $q, int $limit = 20): array;

    /** @return array<int,array{doc_id:int,score:float}> for autocomplete */
    public function suggest(string $q, int $limit = 10): array;

    public function flush(): void;

    /** @return array<string,mixed> e.g. doc_count, avg zone lengths */
    public function stats(): array;
}
