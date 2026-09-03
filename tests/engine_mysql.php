<?php
/**
 * Integration test: the SAME engine on a real MySQL store (PDO).
 * Proves the inverted-index schema + SQL path. Run: php tests/engine_mysql.php
 * Skips gracefully if MySQL is unreachable.
 *
 * Env overrides: LEXA_DB_HOST, LEXA_DB_PORT, LEXA_DB_USER, LEXA_DB_PASS, LEXA_DB_NAME
 */
require __DIR__ . '/../bootstrap.php';

use Lexa\Analysis\Analyzer;
use Lexa\Engine\Document;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\Store\PdoIndexStore;

$host = getenv('LEXA_DB_HOST') ?: '127.0.0.1';
$port = getenv('LEXA_DB_PORT') ?: '3306';
$user = getenv('LEXA_DB_USER') ?: 'root';
$pass = getenv('LEXA_DB_PASS') ?: 'root';
$name = getenv('LEXA_DB_NAME') ?: 'lexa_test';

try {
    $root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
    $root->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4");
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo "SKIP — MySQL not reachable ({$e->getMessage()})\n";
    exit(0);
}

$pass_count = 0;
$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $pass_count, $fail;
    if ($cond) { $pass_count++; echo "  \xE2\x9C\x93 {$msg}\n"; }
    else        { $fail++; echo "  \xE2\x9C\x97 FAIL: {$msg}\n"; }
}
function rankOf(array $results, int $docId): int
{
    foreach ($results as $i => $r) {
        if ($r['doc_id'] === $docId) { return $i; }
    }
    return -1;
}

$store = new PdoIndexStore($pdo, 'lexa_t_');
$store->install();
$store->reset();

$engine = new InvertedIndexEngine($store, new Analyzer(), new EngineConfig());
$engine->bulkIndex([
    Document::make(1, ['title' => 'Máy cưa bàn trượt Makita HS7601 220V', 'sku' => 'HS7601', 'content' => 'thiết bị chế biến gỗ']),
    Document::make(2, ['title' => 'Máy bào cuốn Bosch', 'content' => 'máy cưa cầm tay cho thợ mộc']),
    Document::make(5, ['title' => 'Máy hàn plasma Jasic CUT60', 'sku' => 'CUT60', 'content' => 'máy cắt kim loại plasma']),
    Document::make(6, ['title' => 'Máy khoan Makita']),
]);

echo "MySQL store — index + persistence\n";
ok($engine->stats()['doc_count'] === 4, "doc_count = 4 persisted in MySQL");

echo "MySQL store — query parity with in-memory engine\n";
ok(rankOf($engine->query('máy cưa'), 1) === 0, "'máy cưa' → doc 1 #1 (title weighting)");
ok(rankOf($engine->query('may cua'), 1) === 0, "'may cua' (no diacritics) → doc 1 #1");
ok(rankOf($engine->query('HS7601'), 1) === 0, "'HS7601' → doc 1 #1 (exact code)");
ok(rankOf($engine->query('hs76'), 1) >= 0, "'hs76' (partial) → doc 1 found");
ok(rankOf($engine->query('plasma'), 5) === 0, "'plasma' → doc 5 #1 (rare term)");

echo "MySQL store — exact-byte term match (no collation re-merge)\n";
$df = $store->dfForTerms(['may', 'máy']);
ok($df['may'] >= 1, "term 'may' (folded) indexed");
ok(($df['máy'] ?? 0) >= 1, "term 'máy' (accented) indexed as a DISTINCT row from 'may'");

echo "MySQL store — delete persists\n";
$engine->delete(1);
ok(rankOf($engine->query('HS7601'), 1) === -1, "after delete, doc 1 gone for 'HS7601'");
ok($engine->stats()['doc_count'] === 3, "doc_count = 3 after delete");

$store->dropTables();
echo "(cleaned up test tables)\n";

echo "\n" . str_repeat('=', 48) . "\n";
echo "  {$pass_count} passed, {$fail} failed\n";
echo str_repeat('=', 48) . "\n";
exit($fail > 0 ? 1 : 0);
