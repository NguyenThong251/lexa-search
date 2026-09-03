<?php

namespace Lexa\Wp;

use Lexa\Analysis\Analyzer;
use Lexa\Engine\EngineConfig;
use Lexa\Engine\InvertedIndexEngine;

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
                new EngineConfig()
            );
        }
        return self::$engine;
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
