<?php
/**
 * End-to-end exact-match tests (no DB, no WordPress). Run: php tests/exactmatch_e2e.php
 *
 * tests/exactmatch.php feeds the reranker hand-written scores, so it proves the
 * reranker sorts, not that the SEARCH is fixed. These drive the real
 * InvertedIndexEngine over documents whose CONTENT zone cross-references other
 * machines' model codes — the actual shape of the quocduy.com.vn failure — and
 * pin the chain-order and date-mode interactions the isolated tests cannot see.
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\ChainReranker;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\ExactMatchReranker;
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

$titles = [
    51258 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',
    60001 => 'MÁY TIỆN CNC 2 TRỤC 4 DAO XOAY ( ĐƯA PHÔI TỰ ĐỘNG)',
    60002 => 'MÁY TIỆN CỘT NHÀ – DK500mm – SMQH D5L12',
];
$docs = [
    Document::make(51258, [
        'title'   => $titles[51258],
        'content' => 'Máy tiện gỗ CNC tự động, mâm cặp khí nén, bàn máy gang đúc, bảo hành 12 tháng.',
        'attr'    => 'Máy tiện gỗ CNC',
    ]),
    Document::make(60001, [
        'title'   => $titles[60001],
        'content' => 'Dòng máy này thay thế cho model SMQH 1200 trước đây. Hành trình 1200 mm, 4 dao xoay. '
                   . 'So với SMQH 1200 4 thì tốc độ nhanh hơn. Quý khách tham khảo thêm SMQH 1200, '
                   . 'SMQH 1200 4 đầu và các dòng 1200 khác. Đường kính 4 chấu, 4 trục, khổ 1200.',
        'attr'    => 'Máy tiện gỗ CNC',
    ]),
    Document::make(60002, [
        'title'   => $titles[60002],
        'content' => 'Máy tiện cột nhà SMQH. Tham khảo thêm SMQH 1200 4 và SMQH 1200. '
                   . 'Chiều dài 1200 mm, 4 chấu kẹp, phù hợp cột 1200. Model SMQH cùng dòng 4 dao.',
        'attr'    => 'Máy tiện gỗ CNC',
    ]),
];
$resolveTitles = static fn(array $ids) => array_intersect_key($titles, array_flip($ids));
$mkEngine = static function (?object $rr) use ($docs) {
    $e = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig(), $rr);
    $e->bulkIndex($docs);
    return $e;
};
$now   = time();
$dates = [51258 => $now - 700 * 86400, 60001 => $now - 30 * 86400, 60002 => $now - 60 * 86400];

echo "The fixture really does reproduce the bug under bare BM25F\n";
// If this ever starts passing, the fixture stopped exercising the failure and
// every assertion below becomes vacuous.
$bare = $mkEngine(null)->query('SMQH 1200 4', 10);
ok($bare[0]['doc_id'] !== 51258, "bare BM25F ranks a description-only match above the literal title");

echo "End to end, the shipped chain fixes it\n";
$chain = new ChainReranker(
    new RecencyReranker('medium', 180, static fn(array $ids) => $dates),
    new ExactMatchReranker(new Analyzer(), $resolveTitles)
);
$fixed = $mkEngine($chain)->query('SMQH 1200 4', 10);
ok($fixed[0]['doc_id'] === 51258, "SMQH 1200 4 is #1 through InvertedIndexEngine");
ok(count($fixed) === 3, "no document is dropped");
$top1 = $mkEngine($chain)->query('SMQH 1200 4', 1);
ok(count($top1) === 1 && $top1[0]['doc_id'] === 51258, "limit=1 still returns it (re-rank precedes truncation)");

echo "Chain ORDER is load-bearing: exact-match must run LAST\n";
// With the passes swapped, freshness is applied on top of the tier offsets and
// can lift a tier-2 title over a tier-3 one. tests/exactmatch.php cannot see
// this because its chain fixture uses a tier-3 vs tier-0 gap, which no bounded
// freshness budget can close.
$t = [
    1 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',  // tier 3, ancient
    2 => 'MÁY 4 TRỤC SMQH ĐỜI 2026 KHỔ 1200',        // tier 2, brand new
];
$rt   = static fn(array $ids) => array_intersect_key($t, array_flip($ids));
$rec  = static fn() => new RecencyReranker('strong', 180, static fn(array $i) => [1 => $now - 900 * 86400, 2 => $now]);
$ex   = static fn() => new ExactMatchReranker(new Analyzer(), $rt);
$in   = [2 => 10.0, 1 => 0.5];
ok(array_keys((new ChainReranker($rec(), $ex()))->rerank($in, 'SMQH 1200 4'))[0] === 1,
   "[recency, exact] -> the consecutive-run title wins");

echo "\n--- Boundaries and degradation ---\n";

echo "Mode 'date' survives the exact-match pass inside one tier\n";
// All three titles are tier 3, so the pass must not re-order them; 'date' asked
// for strict newest-first and nothing below it should undo that.
$dt = [1 => 'Máy cưa Makita', 2 => 'Máy cưa Bosch', 3 => 'Máy cưa Dewalt'];
$dc = new ChainReranker(
    new RecencyReranker('date', 180, static fn(array $i) => [1 => $now - 900 * 86400, 2 => $now - 400 * 86400, 3 => $now]),
    new ExactMatchReranker(new Analyzer(), static fn(array $i) => array_intersect_key($dt, array_flip($i)))
);
ok(array_keys($dc->rerank([1 => 10.0, 2 => 8.0, 3 => 5.0], 'máy cưa')) === [3, 2, 1],
   "newest-first is preserved when every title is in the same tier");

echo "The POOL boundary is a real cliff — pin where it is\n";
// POOL must be >= RecencyReranker's 500: that pass runs first and can push a
// document down the list, and anything past the cap is never tiered.
$scores = [];
$many   = [];
for ($i = 1; $i <= 520; $i++) { $scores[$i] = 100.0 - $i * 0.01; $many[$i] = 'Máy khoan bàn số ' . $i; }
$mkPool = static fn(array $tt) => new ExactMatchReranker(new Analyzer(),
    static fn(array $ids) => array_intersect_key($tt, array_flip($ids)));
$at = static function (int $rank) use ($scores, $many, $mkPool): int {
    $t = $many;
    $t[$rank] = 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4';
    return (int) array_search($rank, array_keys($mkPool($t)->rerank($scores, 'SMQH 1200 4')), true) + 1;
};
ok($at(500) === 1, "a literal title match at rank 500 is promoted to #1");
ok($at(501) === 501, "at rank 501 it is NOT promoted (documented POOL cut-off)");
$tailOut = $mkPool($many)->rerank($scores, 'SMQH 1200 4');
ok(count($tailOut) === 520, "documents past POOL are still returned, not dropped");

echo "An empty query leaves the SCORES alone, not just the order\n";
// array_keys() comparison alone would miss a uniform tier offset being added.
$eq = $mkPool($many)->rerank([2 => 9.0, 1 => 7.0], '');
ok($eq === [2 => 9.0, 1 => 7.0], "scores identical, not merely same order");

echo "A misspelled code the engine could not correct must not make things worse\n";
// Applying the correction is the ENGINE's job (pinned by the spy test in
// tests/recency.php); this pass only compares whatever query it is handed. What
// matters here is that an uncorrectable typo leaves the order alone rather than
// demoting the product below its competitors.
$t9   = [1 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4', 2 => 'Máy tiện gỗ 1200 loại 4 chấu'];
$in9  = [2 => 9.0, 1 => 8.0];
$out9 = $mkPool($t9)->rerank($in9, 'SMQG 1200 4');
ok(array_keys($out9) === array_keys($in9), "an uncorrectable typo leaves the BM25F order untouched");
// And once the engine HAS corrected it, the promotion does happen.
$out9b = $mkPool($t9)->rerank($in9, 'SMQH 1200 4');
ok(array_keys($out9b)[0] === 1, "with the corrected query, the SMQH machine is promoted to #1");

echo "\n================================================\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "================================================\n";
exit($fail > 0 ? 1 : 0);
