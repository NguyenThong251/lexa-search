<?php

namespace Lexa\Engine;

/**
 * A normalized, WP-decoupled document. `fields` is a list of
 * ['zone'=>..., 'text'=>...] — zone is the weighting bucket (title/sku/content/…).
 */
final class Document
{
    /** @param array<int,array{zone:string,text:string}> $fields */
    public function __construct(public int $id, public array $fields) {}

    /** @param array<string,string> $zoneText zone => text */
    public static function make(int $id, array $zoneText): self
    {
        $fields = [];
        foreach ($zoneText as $zone => $text) {
            $fields[] = ['zone' => $zone, 'text' => (string) $text];
        }
        return new self($id, $fields);
    }
}
