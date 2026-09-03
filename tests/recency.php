<?php
/**
 * Freshness re-rank tests (no DB, no WordPress). Run: php tests/recency.php
 *
 * The point of these: a ranking regression is silent — the site keeps returning
 * results, they are just in the wrong order — so the ordering rules are pinned
 * here rather than checked by eye on the storefront.
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\RecencyReranker;
use Lexa\Engine\Store\ArrayIndexStore;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \xE2\x9C\x93 {$msg}\n"; }
    else        { $fail++; echo "  \xE2\x9C\x97 FAIL: {$msg}\n"; }
}

$DAY = 86400;
$now = time();

// doc 1 = strongest match but ancient, doc 2 = weaker match posted today,
// doc 3 = weakest and old, doc 4 = mid match, mid age.
$scores = [1 => 10.0, 4 => 6.0, 2 => 5.0, 3 => 1.0];
$ages   = [
    1 => $now - 1200 * $DAY,
    2 => $now - 1 * $DAY,
    3 => $now - 900 * $DAY,
    4 => $now - 200 * $DAY,
];
$resolver = static fn(array $ids) => array_intersect_key($ages, array_flip($ids));

echo "Mode 'off' leaves BM25F untouched\n";
$r = new RecencyReranker('off', 180, $resolver);
ok(array_keys($r->rerank($scores)) === [1, 4, 2, 3], "order unchanged");

echo "An unknown mode degrades to 'off' rather than guessing\n";
$r = new RecencyReranker('nonsense', 180, $resolver);
ok(array_keys($r->rerank($scores)) === [1, 4, 2, 3], "unknown mode = no re-rank");

echo "Mode 'date' sorts strictly newest-first, ignoring relevance\n";
$r = new RecencyReranker('date', 180, $resolver);
ok(array_keys($r->rerank($scores)) === [2, 4, 3, 1], "newest (2) first, oldest (1) last");

echo "Mode 'light' keeps a much stronger old match on top\n";
$r = new RecencyReranker('light', 180, $resolver);
$out = array_keys($r->rerank($scores));
ok($out[0] === 1, "doc 1 (score 10, old) still #1 — relevance still wins");

echo "The strengths are actually distinct and ordered: off < light < medium < strong\n";
// Without this, 'light' could be silently weakened to a no-op (or lose its
// STRENGTH entry and fall through to medium) with every other assertion
// still passing — the boost is invisible unless its magnitude is pinned.
$freshOnly = static fn(array $ids) => [2 => $now - 1 * $DAY];
$scoreOf = static function (string $mode) use ($freshOnly): float {
    $out = (new RecencyReranker($mode, 180, $freshOnly))->rerank([2 => 10.0]);
    return $out[2];
};
$off    = $scoreOf('off');
$light  = $scoreOf('light');
$medium = $scoreOf('medium');
$strong = $scoreOf('strong');
ok($off === 10.0, "off leaves the score untouched (10.0)");
ok($light > $off, sprintf("light boosts (%.3f > %.3f)", $light, $off));
ok($medium > $light, sprintf("medium beats light (%.3f > %.3f)", $medium, $light));
ok($strong > $medium, sprintf("strong beats medium (%.3f > %.3f)", $strong, $medium));

echo "Mode 'strong' lets a fresh, decent match overtake\n";
$r = new RecencyReranker('strong', 180, $resolver);
$out = array_keys($r->rerank($scores));
ok($out[0] === 2, "doc 2 (score 5, posted today) overtakes doc 1 (score 10, 1200d old)");

echo "Half-life controls how fast the boost fades\n";
$short = (new RecencyReranker('medium', 30, $resolver))->rerank($scores);
$long  = (new RecencyReranker('medium', 3650, $resolver))->rerank($scores);
ok($long[4] > $short[4], "doc 4 (200d old) scores higher with a 10y half-life than a 30d one");

echo "A weak match never scores above a strong one purely from the boost cap\n";
$r = new RecencyReranker('medium', 180, $resolver);
$out = $r->rerank([1 => 10.0, 3 => 1.0]);
ok(array_keys($out) === [1, 3], "score 1.0 posted 900d ago cannot pass score 10.0");

echo "Documents with no known date are neither boosted nor penalised\n";
$r = new RecencyReranker('strong', 180, static fn(array $ids) => [2 => time()]);
$out = $r->rerank([1 => 10.0, 2 => 9.0]);
ok(array_keys($out) === [2, 1], "dated doc 2 boosted past undated doc 1");
$r = new RecencyReranker('strong', 180, static fn(array $ids) => [2 => time()]);
$out = $r->rerank([1 => 100.0, 2 => 9.0]);
ok(array_keys($out) === [1, 2], "undated doc 1 keeps its lead when far stronger");

echo "No usable dates at all falls back to pure BM25F\n";
$r = new RecencyReranker('date', 180, static fn(array $ids) => []);
ok(array_keys($r->rerank($scores)) === [1, 4, 2, 3], "empty resolver = untouched order");

echo "Empty input is safe\n";
$r = new RecencyReranker('medium', 180, $resolver);
ok($r->rerank([]) === [], "empty scores => empty result");

echo "Every doc is preserved — re-ranking never drops results\n";
foreach (['light', 'medium', 'strong', 'date'] as $mode) {
    $out = (new RecencyReranker($mode, 180, $resolver))->rerank($scores);
    ok(count($out) === count($scores) && !array_diff_key($out, $scores), "mode '{$mode}' keeps all 4 docs");
}

echo "Wired into the engine, the re-rank runs BEFORE the limit is applied\n";
$engine = new InvertedIndexEngine(
    new ArrayIndexStore(),
    new Analyzer(),
    new EngineConfig(),
    new RecencyReranker('date', 180, static fn(array $ids) => [
        11 => time() - 900 * 86400,
        12 => time() - 1 * 86400,   // newest
    ])
);
$engine->bulkIndex([
    Document::make(11, ['title' => 'Máy cưa Makita HS7601', 'sku' => 'HS7601']),
    Document::make(12, ['title' => 'Máy cưa bàn trượt mới']),
]);
$top1 = $engine->query('máy cưa', 1);
ok(count($top1) === 1 && $top1[0]['doc_id'] === 12, "limit=1 returns the NEWEST doc, not the top-scoring one");

echo "\n================================================\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "================================================\n";
exit($fail > 0 ? 1 : 0);
