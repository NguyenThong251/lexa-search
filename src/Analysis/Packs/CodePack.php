<?php

namespace Lexa\Analysis\Packs;

use Lexa\Analysis\Contracts\LanguagePack;
use Lexa\Analysis\TokenClass;

/**
 * Codes / SKUs / model numbers. Kept WHOLE in a dedicated 'code' field and
 * NEVER folded or stemmed. Index side emits a bounded prefix ladder so partial
 * codes ("hs76") match; query side emits the whole token only (the symmetric
 * invariant). Digit runs become their own sub-tokens so a bare number ("7601")
 * still hits.
 */
final class CodePack implements LanguagePack
{
    public const VERSION = 'code-1';

    public function __construct(private int $minPrefix = 2) {}

    public function id(): string { return 'code'; }
    public function priority(): int { return 10; }

    public function claims(string $surface, string $class): bool
    {
        return $class === TokenClass::ALNUM_CODE || $class === TokenClass::NUMERIC_UNIT;
    }

    public function variants(string $surface, string $lower, string $class, string $mode): array
    {
        $out = [];
        // whole code, exact
        $out[] = ['term' => $lower, 'field' => 'code', 'kind' => 'code_exact'];

        // digit runs (>= 2 chars) so "7601" / "220" are findable on their own
        if (preg_match_all('/[0-9]{2,}/', $lower, $dm)) {
            foreach ($dm[0] as $d) {
                $out[] = ['term' => $d, 'field' => 'code', 'kind' => 'code_digits'];
            }
        }

        // bounded prefix ladder — INDEX side only (query emits the whole token)
        if ($mode === 'index') {
            $len = mb_strlen($lower, 'UTF-8');
            for ($i = $this->minPrefix; $i < $len; $i++) {
                $out[] = ['term' => mb_substr($lower, 0, $i, 'UTF-8'), 'field' => 'code', 'kind' => 'code_prefix'];
            }
        }
        return $out;
    }

    public function stopwords(): array { return []; }

    public function configSignature(): string { return self::VERSION . ':minPrefix=' . $this->minPrefix; }
}
