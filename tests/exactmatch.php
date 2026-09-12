<?php
/**
 * Exact-match re-rank tests (no DB, no WordPress). Run: php tests/exactmatch.php
 *
 * Pinned from a real failure on quocduy.com.vn: searching the model code
 * "SMQH 1200 4" put the machine actually called SMQH 1200 4 in THIRD place,
 * behind two machines that merely mentioned "smqh", "1200" and "4" in their
 * descriptions. BM25F cannot see adjacency, so nothing in the scorer noticed
 * that one title was a literal match.
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\ChainReranker;
use Lexa\Engine\ExactMatchReranker;
use Lexa\Engine\RecencyReranker;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \xE2\x9C\x93 {$msg}\n"; }
    else        { $fail++; echo "  \xE2\x9C\x97 FAIL: {$msg}\n"; }
}

$analyzer = new Analyzer();
$mk = static fn(array $titles) => new ExactMatchReranker(
    new Analyzer(),
    static fn(array $ids) => array_intersect_key($titles, array_flip($ids))
);

echo "REGRESSION — the live \"SMQH 1200 4\" failure\n";
// Scores are the real ones the live engine returned on 2026-09-12.
$titles = [
    51258 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',
    60001 => 'MÁY TIỆN CNC 2 TRỤC 4 DAO XOAY ( ĐƯA PHÔI TỰ ĐỘNG)',
    60002 => 'MÁY TIỆN CỘT NHÀ – DK500mm – SMQH D5L12',
];
$live = [60001 => 8.025, 60002 => 6.937, 51258 => 6.811]; // exact match was LAST
$out  = array_keys($mk($titles)->rerank($live, 'SMQH 1200 4'));
ok($out[0] === 51258, "the machine actually named SMQH 1200 4 is now #1 (was #3)");
ok(count($out) === 3, "the other two are kept, not dropped");

echo "REGRESSION — \"KW 1215XJ\" beat only a blog post by 3%\n";
$t2 = [
    50706 => 'MÁY PHAY TIỆN CNC 5 TRỤC (TRỤC CHÍNH 4 DAO XOAY 360) – KW 1215XJ',
    44982 => 'Giá Máy Tiện Gỗ CNC 2026: Chi Phí Đầu Tư Bao Nhiêu',
    51258 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',
];
// Pretend the blog post had edged ahead, which 3% of headroom easily allows.
$out = array_keys($mk($t2)->rerank([44982 => 11.4, 50706 => 11.39, 51258 => 10.9], 'KW 1215XJ'));
ok($out[0] === 50706, "the product named KW 1215XJ outranks the blog post");

echo "Tiers are ordered: consecutive run > all tokens > some > none\n";
$t3 = [
    1 => 'Máy cưa bàn trượt SMQH 1200 4 chính hãng',   // run
    2 => 'Máy 4 đầu SMQH nhập khẩu loại 1200',          // all tokens, scattered
    3 => 'Máy tiện SMQH đời mới',                        // some
    4 => 'Máy khoan cao tốc',                            // none
];
$out = array_keys($mk($t3)->rerank([4 => 9.9, 3 => 9.8, 2 => 9.7, 1 => 9.6], 'SMQH 1200 4'));
ok($out === [1, 2, 3, 4], "reordered to run, all, some, none (input was the reverse)");

echo "Within one tier the incoming relevance order is preserved\n";
$t4 = [1 => 'Máy cưa Makita', 2 => 'Máy cưa Bosch', 3 => 'Máy cưa Dewalt'];
$out = array_keys($mk($t4)->rerank([2 => 9.0, 3 => 8.0, 1 => 7.0], 'máy cưa'));
ok($out === [2, 3, 1], "all tier 3 => untouched order");

echo "Diacritic-insensitive, like the rest of the engine\n";
$t5 = [1 => 'Bàn thao tác inox', 2 => 'MÁY CƯA BÀN TRƯỢT'];
$out = array_keys($mk($t5)->rerank([1 => 9.0, 2 => 8.0], 'may cua'));
ok($out[0] === 2, "'may cua' promotes 'MÁY CƯA BÀN TRƯỢT' over a doc without it");

echo "A document matching only via its description is demoted, not removed\n";
$t6 = [1 => 'Máy khoan CNC 6 mặt', 2 => 'Máy phay KW 1215XJ'];
$out = $mk($t6)->rerank([1 => 12.0, 2 => 5.0], 'KW 1215XJ');
ok(array_keys($out) === [2, 1], "title match beats a higher-scoring description match");
ok(count($out) === 2 && $out[1] === 12.0, "the demoted doc survives with its score intact");

echo "Degrades safely\n";
ok($mk([])->rerank([1 => 5.0], 'x') === [1 => 5.0], "no titles resolved => untouched");
ok($mk(['1' => 'x'])->rerank([], 'x') === [], "empty scores => empty");
ok(array_keys($mk($t4)->rerank([2 => 9.0, 1 => 7.0], '')) === [2, 1], "empty query => untouched");
$single = [1 => 5.0];
ok($mk($t4)->rerank($single, 'máy cưa') === $single, "single result => untouched");

echo "Chained with freshness, the exact match still wins\n";
$now = time();
$titles7 = [
    1 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',  // exact, but ancient
    2 => 'MÁY PHAY CNC ĐỜI MỚI 2026',                 // brand new, unrelated
];
$chain = new ChainReranker(
    new RecencyReranker('strong', 180, static fn(array $ids) => [1 => $now - 900 * 86400, 2 => $now]),
    $mk($titles7)
);
$out = array_keys($chain->rerank([2 => 10.0, 1 => 9.5], 'SMQH 1200 4'));
ok($out[0] === 1, "freshness nudges, but the literal match still takes #1");

echo "\"Newest first\" survives the chain — the tier pass must not re-sort by score\n";
// Regression: an arsort() here silently reduced recency_mode='date' to 'off',
// because sortByDate() permutes the keys while leaving BM25F values behind.
$t8 = [
    1 => 'MÁY CƯA BÀN TRƯỢT ALPHA',
    2 => 'MÁY CƯA BÀN TRƯỢT BETA',
    3 => 'MÁY CƯA BÀN TRƯỢT GAMMA',
];
$now8   = time();
$dates8 = [1 => $now8 - 900 * 86400, 2 => $now8, 3 => $now8 - 400 * 86400]; // 2 newest, 1 oldest
$chain8 = new ChainReranker(
    new RecencyReranker('date', 180, static fn(array $ids) => $dates8),
    $mk($t8)
);
// All three are tier 3, so only the date ordering can decide.
ok(array_keys($chain8->rerank([1 => 10.0, 3 => 8.0, 2 => 5.0], 'máy cưa bàn trượt')) === [2, 3, 1],
   "date order (newest 2, then 3, then 1) survives, despite the reverse score order");

echo "Chain order is load-bearing: the tier pass must run LAST\n";
// Reversing ChainReranker's arguments used to pass every assertion, which left
// freshness able to overturn a one-tier gap.
$t9 = [
    1 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',   // tier 3, ancient
    2 => 'MÁY 4 TRỤC SMQH ĐỜI 2026 KHỔ 1200',          // tier 2, brand new
];
$dates9 = [1 => time() - 900 * 86400, 2 => time()];
$right  = new ChainReranker(new RecencyReranker('strong', 180, static fn(array $i) => $dates9), $mk($t9));
$wrong  = new ChainReranker($mk($t9), new RecencyReranker('strong', 180, static fn(array $i) => $dates9));
ok(array_keys($right->rerank([2 => 10.0, 1 => 0.5], 'SMQH 1200 4'))[0] === 1, "correct order: literal match wins a one-tier gap");
ok(array_keys($wrong->rerank([2 => 10.0, 1 => 0.5], 'SMQH 1200 4'))[0] === 2, "reversed chain demonstrably breaks it (guards the wiring)");

echo "Scores stay monotonically descending after promotion\n";
// The REST endpoint publishes these scores; a caller that re-sorts by score
// must not be able to undo the ranking.
$out = $mk($t3)->rerank([4 => 9.9, 3 => 9.8, 2 => 9.7, 1 => 9.6], 'SMQH 1200 4');
$vals = array_values($out);
$desc = true;
for ($i = 1; $i < count($vals); $i++) { if ($vals[$i] > $vals[$i - 1]) { $desc = false; break; } }
ok($desc, "score order matches rank order (" . implode(' >= ', array_map(fn($v) => sprintf('%.1f', $v), $vals)) . ")");

echo "\n================================================\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "================================================\n";
exit($fail > 0 ? 1 : 0);
