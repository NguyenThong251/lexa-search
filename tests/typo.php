<?php
/**
 * P0.5 — typo tolerance + "did you mean". Run: php tests/typo.php
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\Store\ArrayIndexStore;

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  \xE2\x9C\x93 {$m}\n"; } else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$m}\n"; } }
function rankOf(array $r, int $id): int { foreach ($r as $i => $x) { if ($x['doc_id'] === $id) return $i; } return -1; }

// tiny test catalog → each term appears once, so allow min-freq 1 here
$engine = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig(['typoMinFreq' => 1]));
$engine->bulkIndex([
    Document::make(1, ['title' => 'Máy cưa bàn trượt']),
    Document::make(2, ['title' => 'Máy bào cuốn']),
    Document::make(3, ['title' => 'Máy đánh mộc']),
    Document::make(4, ['title' => 'Máy chà nhám']),
]);

echo "Auto-correct (1 edit) → still finds the product\n";
$r = $engine->query('may cuaa'); // extra letter
ok(rankOf($r, 1) >= 0, "'may cuaa' → #1 (máy cưa) found");
ok($engine->lastSuggestion() === 'may cua', "suggestion = 'may cua' (was: '" . $engine->lastSuggestion() . "')");

$r = $engine->query('may cwa'); // wrong key
ok(rankOf($r, 1) >= 0, "'may cwa' → máy cưa found");

$r = $engine->query('cau'); // transposition of cua
ok(rankOf($r, 1) >= 0, "'cau' (transposed) → máy cưa found");

$r = $engine->query('may baoo'); // extra letter on bào
ok(rankOf($r, 2) >= 0, "'may baoo' → máy bào found");

echo "Correct query → no false suggestion\n";
$r = $engine->query('may cua');
ok(rankOf($r, 1) >= 0 && $engine->lastSuggestion() === null, "'may cua' → found, no suggestion");

echo "Hard typo (2 edits on short word) → no auto-match, but 'did you mean'\n";
$r = $engine->query('mogk'); // intended 'moc' (mộc), distance 2
ok(count($r) === 0, "'mogk' → 0 auto results (too risky to auto-correct)");
ok($engine->lastSuggestion() === 'moc', "'mogk' → did-you-mean 'moc' (was: '" . $engine->lastSuggestion() . "')");

echo "Nonsense → no results, no suggestion\n";
$r = $engine->query('zzzqxk');
ok(count($r) === 0 && $engine->lastSuggestion() === null, "'zzzqxk' → 0 results, no suggestion");

echo "\n" . str_repeat('=', 48) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 48) . "\n";
exit($fail > 0 ? 1 : 0);
