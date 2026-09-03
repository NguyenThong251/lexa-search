<?php
/**
 * Ranked-search demo over a small sample catalog (in-memory).
 *   php bin/search-demo.php "may cua makita"
 *   php bin/search-demo.php "hs76"
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\Store\ArrayIndexStore;

$titles = [
    1 => 'Máy cưa bàn trượt Makita HS7601 220V',
    2 => 'Máy bào cuốn Bosch (máy cưa cầm tay trong mô tả)',
    3 => 'Bàn thao tác inox',
    4 => 'Ban cong sat my thuat',
    5 => 'Máy hàn plasma Jasic CUT60',
    6 => 'Máy khoan Makita',
    7 => 'Máy mài góc',
    8 => 'Máy nén khí',
];

$engine = new InvertedIndexEngine(new ArrayIndexStore(), new Analyzer(), new EngineConfig());
$engine->bulkIndex([
    Document::make(1, ['title' => 'Máy cưa bàn trượt Makita HS7601 220V', 'sku' => 'HS7601', 'content' => 'thiết bị chế biến gỗ chuyên nghiệp']),
    Document::make(2, ['title' => 'Máy bào cuốn Bosch', 'sku' => 'GWS900', 'content' => 'máy cưa cầm tay rất tốt cho thợ mộc']),
    Document::make(3, ['title' => 'Bàn thao tác inox', 'content' => 'bàn làm việc chắc chắn']),
    Document::make(4, ['title' => 'Ban cong sat my thuat', 'content' => 'lan can cau thang']),
    Document::make(5, ['title' => 'Máy hàn plasma Jasic CUT60', 'sku' => 'CUT60', 'content' => 'máy cắt kim loại plasma']),
    Document::make(6, ['title' => 'Máy khoan Makita']),
    Document::make(7, ['title' => 'Máy mài góc']),
    Document::make(8, ['title' => 'Máy nén khí']),
]);

$q = $argv[1] ?? 'may cua makita';
$results = $engine->query($q, 10);

echo "TÌM: \"{$q}\"\n";
echo str_repeat('—', 64) . "\n";
if (!$results) {
    echo "  (không có kết quả)\n";
} else {
    foreach ($results as $i => $r) {
        printf("  %d. [%6.3f]  #%d  %s\n", $i + 1, $r['score'], $r['doc_id'], $titles[$r['doc_id']] ?? '?');
    }
}
