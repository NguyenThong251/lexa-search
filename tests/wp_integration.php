<?php
/**
 * WordPress integration test — bootstraps the real site and runs the engine
 * through $wpdb (WpdbIndexStore + DocumentFactory) on REAL quocduy products.
 *
 *   php tests/wp_integration.php
 */
$_SERVER['REQUEST_URI'] = '/'; // avoid the theme's login/admin redirects during bootstrap

$wpLoad = dirname(__DIR__, 4) . '/wp-load.php'; // tests -> lexa-search -> plugins -> wp-content -> docroot
if (!is_file($wpLoad)) {
    echo "SKIP — wp-load.php not found at {$wpLoad}\n";
    exit(0);
}
require __DIR__ . '/../bootstrap.php';
require $wpLoad;

use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\EngineConfig;
use Lexa\Analysis\Analyzer;
use Lexa\Wp\WpdbIndexStore;
use Lexa\Wp\DocumentFactory;

global $wpdb;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \xE2\x9C\x93 {$m}\n"; } else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$m}\n"; } }

$store = new WpdbIndexStore($wpdb);
$store->install();
$store->flushAll();
echo "Tables installed (prefix {$wpdb->prefix}lexa_*), index emptied.\n";

$engine = new InvertedIndexEngine($store, new Analyzer(), new EngineConfig());

// Index 60 real published products (favouring ones that have a SKU).
$ids = $wpdb->get_col(
    "SELECT p.ID FROM {$wpdb->posts} p
     WHERE p.post_type='product' AND p.post_status='publish'
     ORDER BY p.ID ASC LIMIT 60"
);
echo 'Indexing ' . count($ids) . " real products...\n";
$t0 = microtime(true);
foreach ($ids as $id) {
    $post = get_post((int) $id);
    if ($post) { $engine->index(DocumentFactory::fromPost($post)); }
}
$engine->flush();
$ms = round((microtime(true) - $t0) * 1000);
echo "Indexed in {$ms}ms.\n";

ok($engine->stats()['doc_count'] === count($ids), 'doc_count matches number of indexed products');

echo "Query 'may cua' (no diacritics) on real data\n";
$r = $engine->query('may cua', 5);
ok(count($r) > 0, "returns results (" . count($r) . ' hits)');
foreach (array_slice($r, 0, 5) as $i => $hit) {
    echo '    ' . ($i + 1) . '. [' . $hit['score'] . '] ' . get_the_title($hit['doc_id']) . "\n";
}

echo "Query by a real SKU from the indexed set\n";
$placeholders = implode(',', array_fill(0, count($ids), '%d'));
$sku = $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value<>'' AND post_id IN ($placeholders) LIMIT 1",
    array_map('intval', $ids)
));
if ($sku) {
    echo "    SKU = {$sku}\n";
    $r = $engine->query($sku, 5);
    ok(count($r) > 0, "SKU query returns the product");
    if ($r) { echo '    top: [' . $r[0]['score'] . '] ' . get_the_title($r[0]['doc_id']) . "\n"; }
} else {
    echo "    (no SKU in the indexed sample — skipping)\n";
}

echo "Re-index one product is idempotent (no posting growth)\n";
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lexa_postings");
$engine->index(DocumentFactory::fromPost(get_post((int) $ids[0])));
$engine->flush();
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lexa_postings");
ok($before === $after, "posting count stable after re-index ({$before})");

echo "\n" . str_repeat('=', 48) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 48) . "\n";
exit($fail > 0 ? 1 : 0);
