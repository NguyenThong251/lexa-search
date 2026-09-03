<?php
/**
 * CLI demo:  php bin/demo.php "Máy cưa bàn trượt Makita HS7601 220V" index
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;

$text = $argv[1] ?? 'Máy cưa bàn trượt Makita HS7601 220V';
$mode = $argv[2] ?? 'index';

$analyzer = new Analyzer();
$result   = $analyzer->analyze($text, $mode);

echo "INPUT : {$text}\n";
echo "MODE  : {$mode}    config_hash: {$result['config_hash']}\n";
echo str_repeat('—', 72) . "\n";
echo "PER-TOKEN ROUTING:\n";
foreach ($result['tokens'] as $t) {
    $vs = [];
    $seen = [];
    foreach ($t['variants'] as $v) {
        $label = "{$v['term']}[{$v['field']}/{$v['kind']}]";
        if (!isset($seen[$label])) {
            $seen[$label] = true;
            $vs[] = $label;
        }
    }
    printf("  %-14s %-15s → %s\n", $t['surface'], $t['class'], implode('  ', $vs));
}
echo str_repeat('—', 72) . "\n";
echo "INDEX TERMS by field:\n";
$byField = [];
foreach ($result['postings'] as $p) {
    $byField[$p['field']][$p['term']] = true;
}
foreach (['text', 'code', 'phrase'] as $f) {
    if (!empty($byField[$f])) {
        echo "  [{$f}]  " . implode(', ', array_keys($byField[$f])) . "\n";
    }
}
