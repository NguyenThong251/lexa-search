<?php

namespace Lexa\Wp;

use Lexa\Analysis\Analyzer;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;
use Lexa\Engine\RecencyReranker;

/**
 * Builds the active search engine for WordPress (MySQL inverted-index over
 * $wpdb). The registry/adapter seam for external engines (Meilisearch, …) is a
 * P7/Pro concern; for now the one engine is resolved here.
 */
final class EngineManager
{
    private static ?InvertedIndexEngine $engine = null;

    public static function engine(): InvertedIndexEngine
    {
        if (self::$engine === null) {
            global $wpdb;
            self::$engine = new InvertedIndexEngine(
                new WpdbIndexStore($wpdb),
                new Analyzer(),
                new EngineConfig(),
                new RecencyReranker(               // a no-op while recency_mode is 'off'
                    Settings::recencyMode(),
                    Settings::recencyHalfLifeDays(),
                    [self::class, 'postTimestamps']
                )
            );
        }
        return self::$engine;
    }

    /**
     * Post dates for the freshness re-rank. Read live rather than stored in the
     * index: post_modified changes on every price/stock edit, so an indexed
     * copy would be stale far more often than not.
     *
     * @param int[] $ids
     * @return array<int,int> docId => unix timestamp (UTC)
     */
    public static function postTimestamps(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        global $wpdb;

        // Interpolated because IN (...) cannot be a single prepare placeholder.
        // The column comes from a fixed whitelist and the ids are cast to int.
        $column = Settings::recencyColumn();
        $in     = implode(',', array_map('intval', $ids));

        $rows = $wpdb->get_results(
            "SELECT ID, {$column} AS ts FROM {$wpdb->posts} WHERE ID IN ({$in})", // phpcs:ignore WordPress.DB
            ARRAY_A
        );

        $out = [];
        foreach ((array) $rows as $row) {
            $raw = (string) ($row['ts'] ?? '');
            // WordPress leaves '0000-00-00 00:00:00' when a GMT date was never
            // written; strtotime() gives nonsense for it.
            if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
                continue;
            }
            $ts = strtotime($raw . ' UTC'); // the _gmt columns are UTC
            if ($ts !== false && $ts > 0) {
                $out[(int) $row['ID']] = $ts;
            }
        }
        return $out;
    }

    public static function store(): WpdbIndexStore
    {
        global $wpdb;
        return new WpdbIndexStore($wpdb);
    }

    /** Total candidate posts for the configured types (published). */
    public static function totalCandidates(): int
    {
        $total = 0;
        foreach (Settings::postTypes() as $type) {
            $counts = wp_count_posts($type);
            if ($counts && isset($counts->publish)) {
                $total += (int) $counts->publish;
            }
        }
        return $total;
    }
}
