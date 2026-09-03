<?php
/**
 * Golden-file test runner for the analyzer core. Run:  php tests/run.php
 * No PHPUnit needed — pure php, exits non-zero on any failure.
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Analysis\Folder;
use Lexa\Analysis\TokenClass;
use Lexa\Analysis\TokenClassifier;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \xE2\x9C\x93 {$msg}\n"; }
    else        { $fail++; echo "  \xE2\x9C\x97 FAIL: {$msg}\n"; }
}
/** @return string[] unique terms in a field (kept as strings — avoid numeric-key coercion) */
function terms(array $r, string $field): array
{
    $o = [];
    foreach ($r['postings'] as $p) {
        if ($p['field'] === $field) { $o[] = (string) $p['term']; }
    }
    return array_values(array_unique($o));
}
function has(array $r, string $field, string $term): bool
{
    return in_array($term, terms($r, $field), true);
}

$cls = new TokenClassifier();
$a   = new Analyzer();

echo "T_classify — per-token classification\n";
ok($cls->classify('Máy')    === TokenClass::WORD_DIACRITIC, "'Máy' → WORD_DIACRITIC");
ok($cls->classify('cưa')    === TokenClass::WORD_DIACRITIC, "'cưa' → WORD_DIACRITIC");
ok($cls->classify('Makita') === TokenClass::WORD_LATIN,     "'Makita' → WORD_LATIN");
ok($cls->classify('HS7601') === TokenClass::ALNUM_CODE,     "'HS7601' → ALNUM_CODE");
ok($cls->classify('220V')   === TokenClass::NUMERIC_UNIT,   "'220V' → NUMERIC_UNIT");
ok($cls->classify('7601')   === TokenClass::NUMERIC_UNIT,   "'7601' → NUMERIC_UNIT");

echo "T6 — Vietnamese fold map (incl. the known-broken sample cases)\n";
ok(Folder::vietnamese('Máy')   === 'may',   "Máy → may");
ok(Folder::vietnamese('cưa')   === 'cua',   "cưa → cua");
ok(Folder::vietnamese('trượt') === 'truot', "trượt → truot");
ok(Folder::vietnamese('giặt')  === 'giat',  "giặt → giat (máy giặt)");
ok(Folder::vietnamese('sữa')   === 'sua',   "sữa → sua (sữa chua)");
ok(Folder::vietnamese('đánh')  === 'danh',  "đánh → danh");
ok(Folder::vietnamese('mộc')   === 'moc',   "mộc → moc");

echo "Fold on DECOMPOSED (NFD) input — intl-free differentiator\n";
$cua_nfd = 'c' . 'u' . mb_chr(0x031B, 'UTF-8') . 'a';     // c + ư(decomposed) + a
$ca_nfd  = 'ca' . mb_chr(0x0301, 'UTF-8');                // cá(decomposed)
ok(Folder::vietnamese($cua_nfd) === 'cua', "decomposed cưa → cua (no ext-intl)");
ok(Folder::vietnamese($ca_nfd)  === 'ca',  "decomposed cá → ca");

echo "T2 — mixed-language INDEX: 'Máy cưa bàn trượt Makita HS7601 220V'\n";
$r = $a->analyze('Máy cưa bàn trượt Makita HS7601 220V', 'index');
foreach (['may', 'cua', 'ban', 'truot', 'makita', 'hs7601', '220v'] as $t) {
    ok(has($r, 'text', $t), "text has '{$t}'");
}
ok(has($r, 'code', 'hs7601'), "code has exact 'hs7601'");
ok(has($r, 'code', '7601'),   "code has digit-run '7601'");
ok(has($r, 'code', '220v'),   "code has '220v'");
ok(has($r, 'code', '220'),    "code has digit-run '220'");
ok(has($r, 'code', 'hs76'),   "code has prefix 'hs76' (index ladder)");
ok(has($r, 'phrase', 'ban_truot'), "phrase has bigram 'ban_truot'");
ok(!has($r, 'code', 'v'),     "no standalone 'v' fragment");
ok(has($r, 'text', 'makita') && !in_array('makit', terms($r, 'text'), true), "'Makita' not stemmed/mangled");

echo "T5 — code query symmetry (query emits whole token, not the ladder)\n";
$rq = $a->analyze('hs76', 'query');
ok(has($rq, 'code', 'hs76') && !has($rq, 'code', 'hs7'), "query 'hs76' emits whole token only");

echo "T3/T4 — queries route correctly\n";
$r3 = $a->analyze('may cua makita', 'query');
ok(has($r3, 'text', 'may') && has($r3, 'text', 'cua') && has($r3, 'text', 'makita'), "'may cua makita' tokens present");
$r4 = $a->analyze('makita table saw', 'query');
ok(has($r4, 'text', 'table') && has($r4, 'text', 'saw'), "english query tokens present");

echo "T7 — stopword scope (VN function words dropped; content words + codes kept)\n";
$r7 = $a->analyze('nồi cơm điện mẹ', 'index'); // content words only
foreach (['noi', 'com', 'dien', 'me'] as $t) {
    ok(has($r7, 'text', $t), "VN content word '{$t}' kept");
}
// VN function word 'của' folds to 'cua' (collides with 'cưa'=saw) → must be stopworded
$r7v = $a->analyze('máy của gỗ', 'index');
ok(has($r7v, 'text', 'may') && has($r7v, 'text', 'go'), "'máy' + 'gỗ' kept");
ok(!has($r7v, 'text', 'cua'), "'của' stopworded → no 'cua' collision with 'cưa'");
$r7b = $a->analyze('the table saw', 'index');
ok(!has($r7b, 'text', 'the') && has($r7b, 'text', 'table'), "English stopword 'the' dropped, 'table' kept");

echo "T8 — codes never folded/stemmed\n";
$r8 = $a->analyze('220V', 'index');
ok(has($r8, 'code', '220v'), "'220V' preserved whole in code field");

echo "T11 — determinism (parity) + stable config_hash\n";
$x1 = $a->analyze('Máy cưa Makita HS7601', 'index');
$x2 = $a->analyze('Máy cưa Makita HS7601', 'index');
ok(json_encode($x1) === json_encode($x2), "analyze() is deterministic (index/query parity)");
ok($a->configHash() === $x1['config_hash'], "config_hash stable");

echo "\n" . str_repeat('=', 48) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 48) . "\n";
exit($fail > 0 ? 1 : 0);
