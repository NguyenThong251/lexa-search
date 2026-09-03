<?php
/**
 * P3 end-to-end: editing/trashing a real product auto-updates the index
 * THROUGH Action Scheduler. Run: php tests/wp_autoindex.php
 * Restores all modified data at the end.
 */
$_SERVER['REQUEST_URI'] = '/';
$docroot = dirname(__DIR__, 4);
if (!is_file($docroot . '/wp-load.php')) { echo "SKIP — wp-load not found\n"; exit(0); }
require __DIR__ . '/../bootstrap.php';
require $docroot . '/wp-load.php';

use Lexa\Wp\EngineManager;
use Lexa\Wp\Drainer;
use Lexa\Wp\SyncSubscriber;

global $wpdb;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \xE2\x9C\x93 {$m}\n"; } else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$m}\n"; } }
function rankOf($r, $id) { foreach ($r as $i => $x) { if ($x['doc_id'] === $id) return $i; } return -1; }

if (!function_exists('as_enqueue_async_action')) { echo "SKIP — Action Scheduler not available\n"; exit(0); }
ok(has_action(SyncSubscriber::HOOK), 'AS handler lexa_index_post is registered (plugin active)');

$engine = EngineManager::engine();
$ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT 2"));
[$P, $Q] = $ids;

echo "A) Edit a product → token appears in search after drain\n";
$token = 'lexaprobe' . substr(md5(uniqid('', true)), 0, 8);
$orig  = get_post($P)->post_content;
ok(rankOf($engine->query($token, 5), $P) === -1, "token not in index before edit");
wp_update_post(['ID' => $P, 'post_content' => wp_slash($orig . "\n" . $token)]);
ok(Drainer::pendingCount() >= 1, "edit enqueued an Action Scheduler job (pending=" . Drainer::pendingCount() . ")");
$drained = Drainer::run();
echo "    drained {$drained} job(s)\n";
ok(rankOf($engine->query($token, 5), $P) === 0, "after drain, product #{$P} is found by the new token");

// restore (simulate a fresh request)
SyncSubscriber::resetRequestCache();
wp_update_post(['ID' => $P, 'post_content' => wp_slash($orig)]);
Drainer::run();
ok(rankOf($engine->query($token, 5), $P) === -1, "after restore + drain, token removed from index");

echo "B) Trash a product → removed from index; untrash → re-indexed\n";
$qTitle = get_post($Q)->post_title;
\Lexa\Wp\Indexer::indexPost($Q); // ensure a known indexed starting state
ok(rankOf($engine->query($qTitle, 10), $Q) >= 0, "product #{$Q} found by its title before trashing");
SyncSubscriber::resetRequestCache();
wp_trash_post($Q);
Drainer::run();
ok(rankOf($engine->query($qTitle, 10), $Q) === -1, "after trash + drain, #{$Q} removed from index");
SyncSubscriber::resetRequestCache();
wp_untrash_post($Q);
wp_publish_post($Q); // untrash restores prior status; ensure published
Drainer::run();
ok(rankOf($engine->query($qTitle, 10), $Q) >= 0, "after untrash + drain, #{$Q} re-indexed");

echo "\n" . str_repeat('=', 48) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 48) . "\n";
exit($fail > 0 ? 1 : 0);
