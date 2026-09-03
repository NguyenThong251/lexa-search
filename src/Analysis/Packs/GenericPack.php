<?php

namespace Lexa\Analysis\Packs;

use Lexa\Analysis\Contracts\LanguagePack;
use Lexa\Analysis\Folder;

/**
 * Fallback pack — always claims. Adds a broad Latin ASCII-fold variant when it
 * differs (catches stray non-Vietnamese diacritics like ñ/ü/ç). For plain ASCII
 * tokens and codes it is a no-op, so it never corrupts a brand or a code.
 */
final class GenericPack implements LanguagePack
{
    public const VERSION = 'generic-1';

    public function id(): string { return 'generic'; }
    public function priority(): int { return 90; }

    public function claims(string $surface, string $class): bool { return true; }

    public function variants(string $surface, string $lower, string $class, string $mode): array
    {
        $fold = Folder::latin($lower);
        if ($fold !== '' && $fold !== $lower) {
            return [['term' => $fold, 'field' => 'text', 'kind' => 'fold']];
        }
        return [];
    }

    public function stopwords(): array { return []; }

    public function configSignature(): string { return self::VERSION . ':' . Folder::VERSION; }
}
