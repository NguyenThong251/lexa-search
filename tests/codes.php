<?php
/**
 * Model-code decomposition (no DB, no WordPress). Run: php tests/codes.php
 *
 * Pinned from a real failure on quocduy.com.vn. The product
 *   "MÁY CẮT KHOAN ĐÓNG CHỐT 2 ĐẦU TỰ ĐỘNG – SM 2000DSBD/SM 2000DSSBD"
 * could not be found by "SM 2000 DSSBD". Customers do not reproduce a
 * catalogue's spacing, but the indexer only emitted the code whole, its DIGIT
 * runs and a prefix ladder — nothing ever emitted "dssbd", so the letters half
 * of a code was unreachable on its own.
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
function terms(Analyzer $a, string $text, string $mode = 'index'): array
{
    $out = [];
    foreach ($a->analyze($text, $mode)['postings'] as $p) { $out[$p['term']] = $p['kind']; }
    return $out;
}

$analyzer = new Analyzer();

echo "Both halves of a code are indexed, not just the digits\n";
$t = terms($analyzer, 'SM 2000DSSBD');
ok(isset($t['2000dssbd']), "the whole code is kept");
ok(isset($t['2000']),      "the digit run is emitted (was already true)");
ok(isset($t['dssbd']),     "the LETTER run is emitted (this was missing)");

echo "An internal separator no longer hides a second code\n";
// The tokenizer deliberately keeps '-' '.' '/' inside codes so AKV3005DK-F
// survives; that made "2000DSBD/SM" a single junk token.
$t = terms($analyzer, 'SM 2000DSBD/SM 2000DSSBD');
ok(isset($t['dsbd']), "'2000DSBD/SM' yields 'dsbd'");
ok(isset($t['sm']),   "'2000DSBD/SM' yields 'sm'");

echo "Single letters are not emitted (they would match everything)\n";
$t = terms($analyzer, 'AKV3005DK-F');
ok(isset($t['akv']) && isset($t['dk']), "runs of 2+ letters are emitted");
ok(!isset($t['f']), "a lone trailing letter is not");

// The real catalogue entries.
$catalog = [
    1 => 'MÁY CẮT KHOAN ĐÓNG CHỐT 2 ĐẦU TỰ ĐỘNG – SM 2000DSBD/SM 2000DSSBD',
    2 => 'MÁY CẮT KHOAN BẮT CHỐT TỰ ĐỘNG – SM 19J',
    3 => 'MÁY PHAY TIỆN CNC 5 TRỤC (TRỤC CHÍNH 4 DAO XOAY 360) – KW 1215XJ',
    4 => 'MÁY TIỆN CNC 4 ĐẦU 1200MM – SMQH 1200 4',
];
$engine = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig());
foreach ($catalog as $id => $title) { $engine->index(Document::make($id, ['title' => $title])); }
$engine->flush();

$firstFor = static function (string $q) use ($engine): int {
    $hits = $engine->query($q, 5);
    return $hits ? (int) $hits[0]['doc_id'] : 0;
};

echo "REGRESSION — every spelling of the code finds the product\n";
foreach ([
    'SM 2000 DSSBD' => 'spaced, as the customer typed it',
    'SM 2000DSSBD'  => 'glued, as the title writes it',
    '2000DSSBD'     => 'code alone',
    'DSSBD'         => 'letters alone',
    'SM 2000 DSBD'  => 'the other code, after the slash',
] as $q => $why) {
    ok($firstFor($q) === 1, "\"{$q}\" -> the right product ({$why})");
}

echo "The same holds for the other catalogue codes\n";
ok($firstFor('KW 1215XJ') === 3, "'KW 1215XJ' (glued in the title)");
ok($firstFor('1215 XJ') === 3, "'1215 XJ' split by the customer");
ok($firstFor('SMQH 1200 4') === 4, "'SMQH 1200 4' still works");
ok($firstFor('SM 19J') === 2, "'SM 19J' still works");

echo "A code that is genuinely absent still returns nothing\n";
ok($engine->query('SM 9999ZZZZ', 5) === [], "an unknown code is not forced onto some product");

echo "Changing the decomposition changes the analyzer fingerprint\n";
// The index must be rebuilt when this changes; AdminPage compares this hash
// against the one stored at build time and warns.
ok((new Analyzer())->configHash() !== '', "configHash() is non-empty");
ok(str_contains(\Lexa\Analysis\Packs\CodePack::VERSION, 'code-'), "CodePack carries a version in the hash (" . \Lexa\Analysis\Packs\CodePack::VERSION . ")");

echo "\n================================================\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "================================================\n";
exit($fail > 0 ? 1 : 0);
