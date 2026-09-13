<?php
/**
 * Typo-correction guardrails (no DB, no WordPress). Run: php tests/typo.php
 *
 * Pinned from a real failure on quocduy.com.vn: searching "SM 2000 DSSBD"
 * landed the customer INSIDE an unrelated machine. DSSBD is not in the
 * catalogue, the corrector rewrote it to "dsb" (2 edits, 2 characters shorter),
 * exactly one product matched the rewritten query via its description, and
 * WooCommerce redirects a one-result product search straight into the product.
 *
 * A code is not a misspelling: rewriting it asks for a different product.
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\FuzzyMatcher;
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

echo "Codes are told apart from words\n";
foreach (['dssbd', 'smqh', 'kw', 'sm', 'hs7601', '1215xj', 'mm'] as $code) {
    ok(FuzzyMatcher::isCodeLike($code), "'{$code}' is a code");
}
foreach (['cua', 'cuaa', 'makita', 'may', 'phun', 'truot', 'bao'] as $word) {
    ok(!FuzzyMatcher::isCodeLike($word), "'{$word}' is a word");
}

echo "A correction may not change the length by more than one\n";
ok(!FuzzyMatcher::isPlausible('dssbd', 'dsb'), "dssbd -> dsb rejected (2 shorter)");
ok(FuzzyMatcher::isPlausible('cuaa', 'cua'), "cuaa -> cua allowed");
ok(FuzzyMatcher::isPlausible('phunn', 'phun'), "phunn -> phun allowed");

// A catalogue containing the words a corrector could reach for.
$engine = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig());
$engine->bulkIndex([
    Document::make(1, ['title' => 'MÁY CẮT KHOAN BẮT CHỐT TỰ ĐỘNG – SM 19J', 'content' => 'Dòng DSB 2000 và SM 2000 tự động.']),
    Document::make(2, ['title' => 'MÁY CƯA BÀN TRƯỢT ALTENDORF', 'content' => 'Máy cưa bàn trượt gỗ công nghiệp.']),
    Document::make(3, ['title' => 'MÁY PHUN SƠN TỰ ĐỘNG', 'content' => 'Súng phun sơn áp lực cao.']),
    // typoMinFreq = 2: a term must appear in at least two documents before the
    // corrector will target it, so "cưa" needs a second product to be reachable.
    Document::make(4, ['title' => 'MÁY CƯA LỌNG ĐỨNG', 'content' => 'Máy cưa lọng cầm tay.']),
]);

echo "REGRESSION — \"SM 2000 DSSBD\" must not be rewritten into another product\n";
$hits = $engine->query('SM 2000 DSSBD', 10);
$sug  = $engine->lastSuggestion();
ok($sug === null || strpos((string) $sug, 'dsb ') === false, "the query is not silently rewritten to a different code (suggestion: " . var_export($sug, true) . ")");
$ids = array_map(static fn($h) => $h['doc_id'], $hits);
ok(!in_array(1, $ids, true) || count($ids) > 1,
   "an unrelated machine is not returned as the single confident answer (got: " . (implode(',', $ids) ?: 'none') . ")");

echo "Genuine word typos are still corrected\n";
$engine->query('may cuaa', 10);
ok($engine->lastSuggestion() !== null, "'may cuaa' still produces a did-you-mean");
$hits = $engine->query('may cuaa', 10);
$found = array_map(static fn($h) => $h['doc_id'], $hits);
ok(in_array(2, $found, true) || in_array(4, $found, true), "'may cuaa' still finds a MÁY CƯA product (got: " . (implode(',', $found) ?: 'none') . ")");

echo "A mistyped code still SUGGESTS, it just does not rewrite the search\n";
$engine->query('SM 2000 DSB0', 10);
ok(true, "no fatal on a code-shaped typo (suggestion: " . var_export($engine->lastSuggestion(), true) . ")");

echo "\n================================================\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "================================================\n";
exit($fail > 0 ? 1 : 0);
