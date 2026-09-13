<?php

namespace Lexa\Analysis\Packs;

use Lexa\Analysis\Contracts\LanguagePack;
use Lexa\Analysis\TokenClass;

/**
 * Codes / SKUs / model numbers. Kept WHOLE in a dedicated 'code' field and
 * NEVER folded or stemmed. Index side emits a bounded prefix ladder so partial
 * codes ("hs76") match; query side emits the whole token only (the symmetric
 * invariant).
 *
 * Digit AND letter runs become their own sub-tokens, so either half of a code
 * is findable alone. Customers do not reproduce a catalogue's spacing: the real
 * product "MÁY CẮT KHOAN … – SM 2000DSBD/SM 2000DSSBD" was unfindable by
 * "SM 2000 DSSBD", because only "2000" and a prefix ladder were indexed and
 * nothing ever emitted "dssbd". Letter runs also rescue codes glued by an
 * internal separator — the tokenizer deliberately keeps "2000DSBD/SM" whole,
 * and this splits it back into "dsbd" and "sm" for retrieval.
 */
final class CodePack implements LanguagePack
{
    public const VERSION = 'code-2';

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

        // letter runs (>= 2 chars) so the alphabetic half of a code is findable
        // on its own, mirroring the digit rule above.
        if (preg_match_all('/[a-z]{2,}/', $lower, $lm)) {
            foreach ($lm[0] as $l) {
                if ($l !== $lower) { // not a duplicate of the whole-code term
                    $out[] = ['term' => $l, 'field' => 'code', 'kind' => 'code_letters'];
                }
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
