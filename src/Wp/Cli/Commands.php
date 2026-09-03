<?php

namespace Lexa\Wp\Cli;

use Lexa\Wp\DocumentFactory;
use Lexa\Wp\EngineManager;
use Lexa\Wp\Settings;

/**
 * WP-CLI: the timeout-free way to build the index (and the unattended path
 * recommended in the admin UI).
 *
 *   wp lexa index [--types=product,post] [--limit=N]
 *   wp lexa status
 *   wp lexa flush
 *   wp lexa search <query>
 */
final class Commands
{
    /**
     * Build/refresh the index for the configured (or given) post types.
     *
     * ## OPTIONS
     * [--types=<types>] : Comma-separated post types. Default: plugin settings.
     * [--limit=<n>]     : Max docs to index (for testing).
     */
    public function index($args, $assoc): void
    {
        $types  = isset($assoc['types']) ? array_map('trim', explode(',', $assoc['types'])) : Settings::postTypes();
        $limit  = isset($assoc['limit']) ? (int) $assoc['limit'] : 0;
        $engine = EngineManager::engine();

        $ids = get_posts([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $total = count($ids);
        $bar = \WP_CLI\Utils\make_progress_bar('Indexing', $total);
        $i = 0;
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post) {
                $engine->index(DocumentFactory::fromPost($post));
            }
            if (++$i % 200 === 0) {
                $engine->flush(); // commit in chunks, not one giant transaction
            }
            $bar->tick();
        }
        $bar->finish();
        $engine->flush();
        if ($limit <= 0) {
            update_option(\Lexa\Wp\QueryIntegration::READY_OPTION, 1); // full build → front-end may use the engine
        }
        \WP_CLI::success(sprintf('Indexed %d docs. Total in index: %d.', $total, $engine->stats()['doc_count']));
    }

    public function status(): void
    {
        $stats = EngineManager::engine()->stats();
        $last  = \Lexa\Wp\Drainer::lastDrainAt();
        \WP_CLI::log('Indexed docs : ' . $stats['doc_count']);
        \WP_CLI::log('Candidates   : ' . EngineManager::totalCandidates() . ' (' . implode(', ', Settings::postTypes()) . ')');
        // Both halves of the real predicate — an index that is built but a kill
        // switch that is off still means the front end uses WordPress's search.
        $indexReady = (bool) get_option(\Lexa\Wp\QueryIntegration::READY_OPTION);
        \WP_CLI::log('Index ready  : ' . ($indexReady ? 'yes' : 'no'));
        \WP_CLI::log('Front search : ' . (\Lexa\Wp\QueryIntegration::active()
            ? 'engine'
            : 'WordPress default' . ($indexReady ? '  [engine switched OFF in Settings]' : '')));
        \WP_CLI::log('Recency      : ' . Settings::recencyMode()
            . ' (' . Settings::recencyColumn() . ', half-life ' . Settings::recencyHalfLifeDays() . 'd)');
        \WP_CLI::log('Pending jobs : ' . \Lexa\Wp\Drainer::pendingCount() . (\Lexa\Wp\Drainer::isStalled() ? '  [STALLED — run: wp lexa run]' : ''));
        \WP_CLI::log('Last drain   : ' . ($last ? human_time_diff($last) . ' ago' : 'never'));
    }

    /**
     * Drain pending auto-index jobs (the unattended path; put on a server cron).
     *
     * ## OPTIONS
     * [--limit=<n>] : Max jobs to process. Default 500.
     */
    public function run($args, $assoc): void
    {
        $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 500;
        $done  = \Lexa\Wp\Drainer::run($limit);
        \WP_CLI::success("Drained {$done} job(s). Remaining pending: " . \Lexa\Wp\Drainer::pendingCount());
    }

    /** Reconcile the index with the catalog: remove orphans, index missing. */
    public function reconcile(): void
    {
        global $wpdb;
        $engine  = EngineManager::engine();
        $table   = $wpdb->prefix . 'lexa_docs';
        $indexed = array_map('intval', (array) $wpdb->get_col("SELECT doc_id FROM `{$table}`"));
        $valid   = array_map('intval', (array) get_posts([
            'post_type'      => Settings::postTypes(),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]));
        $orphans = array_diff($indexed, $valid);
        $missing = array_diff($valid, $indexed);
        foreach ($orphans as $id) {
            $engine->delete((int) $id);
        }
        foreach ($missing as $id) {
            $post = get_post((int) $id);
            if ($post) {
                $engine->index(DocumentFactory::fromPost($post));
            }
        }
        $engine->flush();
        \WP_CLI::success(sprintf('Reconcile: removed %d orphan(s), indexed %d missing.', count($orphans), count($missing)));
    }

    public function flush(): void
    {
        EngineManager::store()->flushAll();
        delete_option(\Lexa\Wp\QueryIntegration::READY_OPTION);
        \WP_CLI::success('Index emptied.');
    }

    /**
     * Run a query and print ranked results.
     *
     * ## OPTIONS
     * <query>... : The search terms.
     */
    public function search($args, $assoc): void
    {
        $q = implode(' ', $args);
        $results = EngineManager::engine()->query($q, 10);
        if (!$results) {
            \WP_CLI::warning('No results.');
            return;
        }
        $rows = [];
        foreach ($results as $r) {
            $rows[] = ['score' => $r['score'], 'id' => $r['doc_id'], 'title' => get_the_title($r['doc_id'])];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['score', 'id', 'title']);
    }
}
