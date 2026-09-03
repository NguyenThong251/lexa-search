<?php

namespace Lexa\Analysis;

/**
 * The analyzer pipeline — pure and deterministic. The SAME instance/config is
 * used at index and query time; analyze() is side-effect-free so index/query
 * parity is guaranteed (and pinned by config_hash).
 *
 * Flow: tokenize -> classify each token -> emit base lowercased text token ->
 * co-apply every claiming pack (additive variants) -> emit syllable bigrams
 * across adjacent Vietnamese tokens -> drop language-scoped stopwords.
 */
final class Analyzer
{
    private Tokenizer $tokenizer;
    private TokenClassifier $classifier;
    private AnalyzerConfig $config;

    public function __construct(?AnalyzerConfig $config = null)
    {
        $this->config     = $config ?? AnalyzerConfig::default();
        $this->tokenizer  = new Tokenizer();
        $this->classifier = new TokenClassifier();
    }

    public function configHash(): string
    {
        return $this->config->hash();
    }

    /**
     * @param string $mode 'index' | 'query'
     * @return array{mode:string,config_hash:string,tokens:array,postings:array}
     */
    public function analyze(string $text, string $mode = 'index'): array
    {
        $tokens   = [];
        $postings = [];
        $seen     = [];   // [pos][field|term] => true, dedup
        $viFolded = [];   // pos => folded term, for bigram emission

        $pos = 0;
        foreach ($this->tokenizer->tokenize($text) as $span) {
            $surface = $span['surface'];
            $class   = $this->classifier->classify($surface);
            $lower   = mb_strtolower($surface, 'UTF-8');

            $variants = [];
            $emit = function (string $term, string $field, string $kind) use (&$variants, &$postings, &$seen, $pos): void {
                if ($term === '') {
                    return;
                }
                $variants[] = ['term' => $term, 'field' => $field, 'kind' => $kind];
                $key = $field . '|' . $term;
                if (!isset($seen[$pos][$key])) {
                    $seen[$pos][$key] = true;
                    $postings[] = ['term' => $term, 'field' => $field, 'kind' => $kind, 'pos' => $pos];
                }
            };

            // base text token (accented, lowercased) — searchable as typed
            $emit($lower, 'text', 'lower');

            $isStopword = false;
            foreach ($this->config->packs() as $pack) {
                if (!$pack->claims($surface, $class)) {
                    continue;
                }
                foreach ($pack->variants($surface, $lower, $class, $mode) as $v) {
                    $emit($v['term'], $v['field'], $v['kind']);
                }
                if (in_array($lower, $pack->stopwords(), true)) {
                    $isStopword = true;
                }
                if ($pack->id() === 'vi') {
                    $viFolded[$pos] = Folder::vietnamese($lower);
                }
            }

            $tokens[] = [
                'pos'         => $pos,
                'surface'     => $surface,
                'class'       => $class,
                'lower'       => $lower,
                'is_stopword' => $isStopword,
                'variants'    => $variants,
            ];
            $pos++;
        }

        // syllable bigrams across adjacent Vietnamese tokens (phrase recall)
        if ($this->config->option('bigrams', true)) {
            $positions = array_keys($viFolded);
            sort($positions);
            for ($i = 0; $i + 1 < count($positions); $i++) {
                $a = $positions[$i];
                $b = $positions[$i + 1];
                if ($b === $a + 1) {
                    $postings[] = [
                        'term'  => $viFolded[$a] . '_' . $viFolded[$b],
                        'field' => 'phrase',
                        'kind'  => 'bigram',
                        'pos'   => $a,
                    ];
                }
            }
        }

        // language-scoped stopword removal: drop the plain text token of a
        // stopword position, but only if non-stopword tokens remain (never
        // empty a query) and never the code/phrase fields.
        $stopPositions = [];
        $nonStop = 0;
        foreach ($tokens as $t) {
            if ($t['is_stopword']) {
                $stopPositions[$t['pos']] = true;
            } else {
                $nonStop++;
            }
        }
        if ($nonStop > 0 && $stopPositions) {
            $postings = array_values(array_filter($postings, function (array $p) use ($stopPositions): bool {
                return !($p['field'] === 'text' && isset($stopPositions[$p['pos']]));
            }));
        }

        return [
            'mode'        => $mode,
            'config_hash' => $this->config->hash(),
            'tokens'      => $tokens,
            'postings'    => $postings,
        ];
    }
}
