<?php
/**
 * BM25F engine tests (in-memory store, plain php). Run: php tests/engine.php
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\Store\ArrayIndexStore;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \xE2\x9C\x93 {$msg}\n"; }
    else        { $fail++; echo "  \xE2\x9C\x97 FAIL: {$msg}\n"; }
}
/** position of a doc in ranked results, or -1 */
function rankOf(array $results, int $docId): int
{
    foreach ($results as $i => $r) {
        if ($r['doc_id'] === $docId) { return $i; }
    }
    return -1;
}
function ids(array $results): array { return array_map(fn($r) => $r['doc_id'], $results); }

$engine = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig());

$catalog = [
    Document::make(1, ['title' => 'Máy cưa bàn trượt Makita HS7601 220V', 'sku' => 'HS7601', 'content' => 'thiết bị chế biến gỗ chuyên nghiệp']),
    Document::make(2, ['title' => 'Máy bào cuốn Bosch', 'sku' => 'GWS900', 'content' => 'máy cưa cầm tay rất tốt cho thợ mộc']),
    Document::make(3, ['title' => 'Bàn thao tác inox', 'content' => 'bàn làm việc chắc chắn']),
    Document::make(4, ['title' => 'Ban cong sat my thuat', 'content' => 'lan can cau thang']),
    Document::make(5, ['title' => 'Máy hàn plasma Jasic CUT60', 'sku' => 'CUT60', 'content' => 'máy cắt kim loại plasma']),
    Document::make(6, ['title' => 'Máy khoan Makita']),
    Document::make(7, ['title' => 'Máy mài góc']),
    Document::make(8, ['title' => 'Máy nén khí']),
];
$engine->bulkIndex($catalog);

echo "Index built\n";
ok($engine->stats()['doc_count'] === 8, "doc_count = 8 after bulkIndex");

echo "Field weighting — title match beats content match\n";
$r = $engine->query('máy cưa');
ok(rankOf($r, 1) === 0, "doc 1 (máy cưa in TITLE) ranks #1");
ok(rankOf($r, 2) >= 0 && rankOf($r, 2) > rankOf($r, 1), "doc 2 (cưa only in content) ranks below doc 1");

echo "Diacritic-insensitive — no-diacritics query still finds accented product\n";
$r = $engine->query('may cua');
ok(rankOf($r, 1) === 0, "'may cua' (no diacritics) → doc 1 #1");

echo "Code / SKU exact + partial\n";
$r = $engine->query('HS7601');
ok(rankOf($r, 1) === 0, "'HS7601' → doc 1 #1 (exact code)");
$r = $engine->query('hs76');
ok(rankOf($r, 1) >= 0, "'hs76' (partial code) → doc 1 found via prefix ladder");
$r = $engine->query('7601');
ok(rankOf($r, 1) >= 0, "'7601' (bare digits) → doc 1 found");

echo "Exact-accent preference — accented token outranks bare-latin lookalike\n";
$r = $engine->query('bàn');
ok(rankOf($r, 3) >= 0, "doc 3 ('Bàn') matches 'bàn'");
ok(rankOf($r, 4) === -1 || rankOf($r, 3) < rankOf($r, 4), "accented 'Bàn' (doc3) ranks above bare 'Ban' (doc4)");

echo "IDF — a rare term drives ranking over a common one\n";
$r = $engine->query('máy plasma');
ok(rankOf($r, 5) === 0, "doc 5 (has rare 'plasma') ranks #1 for 'máy plasma'");
ok(rankOf($r, 6) === -1 || rankOf($r, 5) < rankOf($r, 6), "a 'máy'-only doc ranks below the 'plasma' doc");

echo "English token routes cleanly (no VN interference)\n";
$r = $engine->query('makita');
ok(rankOf($r, 1) >= 0 && rankOf($r, 6) >= 0, "'makita' finds both Makita products (doc1 + doc6)");

echo "Delete removes a doc from results\n";
$engine->delete(1);
$r = $engine->query('HS7601');
ok(rankOf($r, 1) === -1, "after delete, doc 1 no longer returned for 'HS7601'");
ok($engine->stats()['doc_count'] === 7, "doc_count = 7 after delete");

echo "Re-index updates cleanly (no stale postings)\n";
$engine->index(Document::make(2, ['title' => 'Máy bào cuốn Bosch đã đổi tên']));
$r = $engine->query('cầm tay');
ok(rankOf($r, 2) === -1, "old content ('cầm tay') gone from doc 2 after re-index");

echo "Empty / no-match queries are safe\n";
ok($engine->query('') === [], "empty query → []");
ok($engine->query('zzzxxxqqq') === [], "no-match query → []");

echo "\n" . str_repeat('=', 48) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 48) . "\n";
exit($fail > 0 ? 1 : 0);
