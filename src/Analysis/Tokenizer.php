<?php

namespace Lexa\Analysis;

/**
 * Language-agnostic tokenizer. Splits on whitespace/punctuation but keeps
 * internal separators ( - _ . / ) so codes stay whole (AKV3005DK-F, QD-1234),
 * and keeps combining marks (\p{M}) attached so decomposed (NFD) input is not
 * split mid-character.
 */
final class Tokenizer
{
    public const VERSION = 'tok-1';

    /** @return array<int,array{surface:string,offset:int}> */
    public function tokenize(string $text): array
    {
        $tokens = [];
        if (!preg_match_all('/[\p{L}\p{N}\p{M}][\p{L}\p{N}\p{M}_.\-\/]*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $tokens;
        }
        foreach ($m[0] as $match) {
            // trim separator characters from the edges (e.g. a trailing "." )
            $surface = preg_replace('/^[_.\-\/]+|[_.\-\/]+$/u', '', $match[0]);
            if ($surface === '' || $surface === null) {
                continue;
            }
            $tokens[] = ['surface' => $surface, 'offset' => $match[1]];
        }
        return $tokens;
    }
}
