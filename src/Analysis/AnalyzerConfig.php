<?php

namespace Lexa\Analysis;

use Lexa\Analysis\Contracts\LanguagePack;
use Lexa\Analysis\Packs\CodePack;
use Lexa\Analysis\Packs\EnglishPack;
use Lexa\Analysis\Packs\GenericPack;
use Lexa\Analysis\Packs\VietnamesePack;

/**
 * The serializable analyzer configuration. Its config_hash binds the WHOLE
 * pipeline (ordered packs + versions + tokenizer + folder + options). The index
 * stores the hash it was built with; query-time must use the same hash or the
 * "dropdown and results never disagree" guarantee fails.
 */
final class AnalyzerConfig
{
    /** @var LanguagePack[] */
    private array $packs;
    private array $options;

    /** @param LanguagePack[] $packs */
    public function __construct(array $packs, array $options = [])
    {
        usort($packs, fn(LanguagePack $a, LanguagePack $b) => $a->priority() <=> $b->priority());
        $this->packs   = $packs;
        $this->options = $options + ['bigrams' => true];
    }

    /** @return LanguagePack[] */
    public function packs(): array { return $this->packs; }

    public function option(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function hash(): string
    {
        $packParts = [];
        foreach ($this->packs as $p) {
            $packParts[] = $p->id() . '@' . $p->priority() . '#' . $p->configSignature();
        }
        $canonical = json_encode([
            'packs'     => $packParts,
            'tokenizer' => Tokenizer::VERSION,
            'folder'    => Folder::VERSION,
            'options'   => $this->options,
        ], JSON_UNESCAPED_UNICODE);

        return substr(hash('sha256', (string) $canonical), 0, 16);
    }

    /** The default 4-pack MVP config. */
    public static function default(): self
    {
        return new self([
            new CodePack(),
            new VietnamesePack(),
            new EnglishPack(false),
            new GenericPack(),
        ]);
    }
}
