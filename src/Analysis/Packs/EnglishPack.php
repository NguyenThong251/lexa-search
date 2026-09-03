<?php

namespace Lexa\Analysis\Packs;

use Lexa\Analysis\Contracts\LanguagePack;
use Lexa\Analysis\TokenClass;

/**
 * English pack. Claims ONLY plain-ASCII words. Stemming is OFF in the free core
 * (precision-safe: aggressive stemming over-conflates brand-like tokens and hurts
 * e-commerce precision). Full Snowball stemming is a Pro upgrade (dual-token).
 *
 * Stopwords are LANGUAGE-SCOPED and deliberately conservative — they exclude
 * words that collide with folded Vietnamese ("me","la","than","can",...), so a
 * Vietnamese word is never silently dropped.
 */
final class EnglishPack implements LanguagePack
{
    public const VERSION = 'en-1';

    public function __construct(private bool $stem = false) {}

    public function id(): string { return 'en'; }
    public function priority(): int { return 30; }

    public function claims(string $surface, string $class): bool
    {
        return $class === TokenClass::WORD_LATIN;
    }

    public function variants(string $surface, string $lower, string $class, string $mode): array
    {
        // MVP: stemming OFF. (Pro: emit a low-weight stem into a separate sub-field.)
        return [];
    }

    public function stopwords(): array
    {
        return ['the', 'a', 'an', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'with', 'is', 'are', 'be'];
    }

    public function configSignature(): string { return self::VERSION . ':stem=' . ($this->stem ? '1' : '0'); }
}
