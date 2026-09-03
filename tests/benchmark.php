<?php
/**
 * P5 benchmark — engine (BM25F) vs the old title-only AND LIKE, on the real
 * quocduy catalog. Run: php tests/benchmark.php
 */
$_SERVER['REQUEST_URI'] = '/';
$docroot = dirname(__DIR__, 4);
require __DIR__ . '/../bootstrap.php';
require $docroot . '/wp-load.php';

use Lexa\Wp\EngineManager;

global $wpdb;
$engine = EngineManager::engine();

/** Replicate the theme's OLD product search: title-only, every word AND, date order. */
function old_like(\wpdb $wpdb, string $q): array
{
    $words  = preg_split('/\s+/', trim($q));
    $where  = '';
    $params = ['product', 'publish'];
    foreach ($words as $w) {
        if ($w === '') { continue; }
        $where    .= ' AND post_title LIKE %s';
        $params[]  = '%' . $wpdb->esc_like($w) . '%';
    }
    $sql = $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s {$where} ORDER BY post_date DESC LIMIT 500",
        $params
    );
    return array_map('intval', (array) $wpdb->get_col($sql));
}

function median_ms(callable $fn, int $reps = 5): array
{
    $times = [];
    $res = [];
    for ($i = 0; $i < $reps; $i++) {
        $t0 = microtime(true);
        $res = $fn();
        $times[] = (microtime(true) - $t0) * 1000;
    }
    sort($times);
    return [$res, round($times[(int) floor($reps / 2)], 1)];
}

$queries = [
    'may cua',          // common, no diacritics
    'máy cưa',          // with diacritics
    'may cua makita',   // multi-word + brand
    'bàn trượt',        // phrase
    'may danh moc',     // user's earlier example, no diacritics
    'máy bào',
    'cnc router',       // english-ish
    'động cơ',          // likely in DESCRIPTION, not titles
    'công suất',        // likely in DESCRIPTION
    'bảo hành',         // likely in DESCRIPTION
];

echo "BENCHMARK — engine (BM25F) vs old title-only LIKE · catalog = " . $engine->stats()['doc_count'] . " products\n";
echo str_repeat('=', 78) . "\n";
printf("%-18s %10s %10s %10s %10s\n", 'query', 'OLD n', 'NEW n', 'OLD ms', 'NEW ms');
echo str_repeat('-', 78) . "\n";

$sumOld = $sumNew = 0.0;
$coverageWins = 0;
$details = [];

foreach ($queries as $q) {
    [$oldIds, $oldMs] = median_ms(fn() => old_like($wpdb, $q));
    [$newHits, $newMs] = median_ms(fn() => $engine->query($q, 500));
    $newIds = array_map(fn($h) => (int) $h['doc_id'], $newHits);

    $sumOld += $oldMs;
    $sumNew += $newMs;
    if (count($oldIds) === 0 && count($newIds) > 0) { $coverageWins++; }

    printf("%-18s %10d %10d %10s %10s\n", mb_strimwidth($q, 0, 18), count($oldIds), count($newIds), $oldMs, $newMs);
    $details[$q] = ['old' => count($oldIds), 'new' => $newIds];
}
echo str_repeat('-', 78) . "\n";
printf("%-18s %10s %10s %10s %10s\n", 'AVG', '', '', round($sumOld / count($queries), 1), round($sumNew / count($queries), 1));

echo "\nCOVERAGE: " . $coverageWins . " / " . count($queries) . " queries returned results in the engine where the OLD title-only search returned ZERO.\n";

echo "\nTOP-3 ranking samples (engine):\n";
foreach (['may cua makita', 'động cơ', 'bàn trượt'] as $q) {
    echo "  \"{$q}\":\n";
    foreach (array_slice($details[$q]['new'], 0, 3) as $id) {
        echo '      · ' . html_entity_decode(wp_strip_all_tags(get_the_title($id))) . "\n";
    }
    echo "      (old title-only LIKE: {$details[$q]['old']} kết quả)\n";
}
