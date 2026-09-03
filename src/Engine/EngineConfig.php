<?php

namespace Lexa\Engine;

/**
 * BM25F tuning: per-zone weights (title > sku/code > attr/phrase > content),
 * per-kind weights (exact/fold full = 1.0; prefix/bigram/stem reduced so a
 * whole-token match always beats a partial one), and k1/b.
 */
final class EngineConfig
{
    public array $zoneWeights;
    public array $kindWeights;
    public float $k1;
    public float $b;
    public bool $typo;
    public int $typoMaxDocs;
    public int $typoVocabCap;
    public int $typoMinFreq;

    public function __construct(array $o = [])
    {
        $this->typo         = $o['typo'] ?? true;
        $this->typoMaxDocs  = $o['typoMaxDocs'] ?? 100000; // gate: skip fuzzy on very large catalogs
        $this->typoVocabCap = $o['typoVocabCap'] ?? 4000;
        $this->typoMinFreq  = $o['typoMinFreq'] ?? 2;      // never "correct" to a one-off/junk term
        $this->zoneWeights = ($o['zoneWeights'] ?? []) + [
            'title' => 8.0, 'sku' => 6.0, 'code' => 6.0, 'attr' => 3.0, 'phrase' => 4.0, 'content' => 1.0,
        ];
        $this->kindWeights = ($o['kindWeights'] ?? []) + [
            'lower' => 1.0, 'original' => 1.0, 'fold' => 1.0, 'code_exact' => 1.0,
            'code_digits' => 0.85, 'bigram' => 0.7, 'code_prefix' => 0.35, 'stem' => 0.5,
        ];
        $this->k1 = $o['k1'] ?? 1.2;
        $this->b  = $o['b'] ?? 0.75;
    }

    public function zoneWeight(string $zone): float { return $this->zoneWeights[$zone] ?? 1.0; }
    public function kindWeight(string $kind): float { return $this->kindWeights[$kind] ?? 0.5; }
}
