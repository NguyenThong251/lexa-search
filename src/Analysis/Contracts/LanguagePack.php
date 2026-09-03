<?php

namespace Lexa\Analysis\Contracts;

/**
 * A language pack reacts to a token's class and emits ADDITIONAL searchable
 * variants. Packs are pure and stateless; multiple packs co-apply per token
 * in priority order. A pack MUST return false from claims() for any class it
 * must not touch — that is the guarantee one language never corrupts another.
 */
interface LanguagePack
{
    public function id(): string;

    /** Lower runs earlier. code=10, vi=20, en=30, generic=90. */
    public function priority(): int;

    public function claims(string $surface, string $class): bool;

    /**
     * Extra variants for this token (the analyzer already emits the base
     * lowercased text token). Each variant: ['term'=>..,'field'=>'text'|'code'|'phrase','kind'=>..].
     * $mode is 'index' or 'query' (e.g. code emits a prefix ladder only when indexing).
     *
     * @return array<int,array{term:string,field:string,kind:string}>
     */
    public function variants(string $surface, string $lower, string $class, string $mode): array;

    /** Language-scoped stopwords (lowercased). Only consulted for this pack's own tokens. */
    public function stopwords(): array;

    /** Folded into the global config_hash so a config change invalidates the index. */
    public function configSignature(): string;
}
