<?php
/**
 * SKU V2 (_sku_v2) reaches the search index. Needs wp-load.php, writes nothing:
 * _sku_v2 is injected in memory via get_post_metadata, index is ArrayIndexStore.
 * Run: php tests/sku_v2.php
 */
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require __DIR__ . '/../../../../wp-load.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\Store\ArrayIndexStore;
use Lexa\Wp\DocumentFactory;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \xE2\x9C\x93 {$msg}\n"; }
    else        { $fail++; echo "  \xE2\x9C\x97 FAIL: {$msg}\n"; }
}

$ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 300, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);
ok(count($ids) > 10, 'loaded ' . count($ids) . ' real products');

$target = $ids[intdiv(count($ids), 2)];
$fakeV2 = [$target => 'KW TC2+2'];
add_filter('get_post_metadata', static function ($value, $id, $key) use ($fakeV2) {
    if ($key === '_sku_v2' && isset($fakeV2[$id])) {
        return [$fakeV2[$id]];
    }
    return $value;
}, 10, 3);

$doc = DocumentFactory::fromPost(get_post($target));
$rawSku = (string) get_post_meta($target, '_sku', true);
echo "  target #{$target}: _sku=" . ($rawSku === '' ? '(empty)' : $rawSku) . "\n";
$zones = array_column($doc->fields, 'text', 'zone');
echo "  zones: " . implode(', ', array_keys($zones)) . " | sku=" . ($zones['sku'] ?? '(none)') . "\n";
ok(($zones['sku'] ?? '') === 'KW TC2+2', 'sku zone holds _sku_v2 only');

$linkInSku = 0;
foreach ($ids as $id) {
    $z = array_column(DocumentFactory::fromPost(get_post($id))->fields, 'text', 'zone');
    if (isset($z['sku']) && stripos($z['sku'], 'http') !== false) { $linkInSku++; }
}
ok($linkInSku === 0, "no _sku link indexed as sku ({$linkInSku} found)");

$control = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig());
foreach ($ids as $id) {
    if ($id !== $target) { $control->index(DocumentFactory::fromPost(get_post($id))); }
}
$c = $control->query('KW TC2+2', 5);
ok(!in_array($target, array_column($c, 'doc_id'), true), 'control: without SKU V2 product, query does not return it');

$engine = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig());
foreach ($ids as $id) {
    $engine->index(DocumentFactory::fromPost(get_post($id)));
}

foreach (['KW TC2+2', 'kw tc2+2', 'TC2+2'] as $q) {
    $res = $engine->query($q, 5);
    $top = $res[0]['doc_id'] ?? null;
    echo "  query '{$q}' → top=" . ($top ?? 'none') . ' | ' . ($top ? get_the_title($top) : '') . "\n";
    ok($top === $target, "'{$q}' ranks the SKU V2 product first");
}

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
