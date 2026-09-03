<?php

namespace Lexa\Wp;

/**
 * Front-end query integration (P4). Hands the main product search query to the
 * engine: run the engine, inject the ranked IDs as post__in + orderby, and
 * neutralize the SQL LIKE search — while leaving WooCommerce's own
 * product_visibility / out-of-stock clauses to run, so hidden products never
 * leak.
 *
 * Safety:
 *  - only the MAIN, front-end, product search query is touched;
 *  - a kill switch (Settings 'enabled') and a `lexa_ready` flag (set only after
 *    a FULL index build) mean a missing/partial index falls back to the site's
 *    existing search instead of hijacking it with incomplete results.
 */
final class QueryIntegration
{
    public const READY_OPTION = 'lexa_ready';

    public static function register(): void
    {
        add_action('pre_get_posts', [self::class, 'maybeHandle']);
        add_filter('posts_search', [self::class, 'neutralizeSearch'], 999, 2);
        add_action('rest_api_init', [self::class, 'restRoutes']);
    }

    public static function active(): bool
    {
        return Settings::isEnabled() && (bool) get_option(self::READY_OPTION);
    }

    public static function maybeHandle($query): void
    {
        if (is_admin() || !($query instanceof \WP_Query) || !$query->is_main_query()) {
            return;
        }
        if (!self::active() || !$query->is_search()) {
            return;
        }
        $pt = $query->get('post_type');
        $isProduct = $pt === 'product' || (is_array($pt) && in_array('product', $pt, true));
        if (!$isProduct) {
            return;
        }
        $s = trim((string) $query->get('s'));
        if ($s === '') {
            return;
        }

        $hits = EngineManager::engine()->query($s, 500);
        $ids  = array_values(array_map(static fn($h) => (int) $h['doc_id'], $hits));

        $query->set('lexa_handled', 1);
        $query->set('post__in', $ids ?: [0]); // [0] => deliberately empty result set
        $query->set('orderby', 'post__in');   // preserve BM25F ranking
        // keep 's' so the "you searched for X" title still renders; the LIKE is
        // removed by neutralizeSearch() below.
    }

    public static function neutralizeSearch($search, $query)
    {
        if ($query instanceof \WP_Query && $query->get('lexa_handled')) {
            return ''; // engine already chose the matches via post__in
        }
        return $search;
    }

    public static function restRoutes(): void
    {
        register_rest_route('lexa/v1', '/search', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => ['q' => ['required' => true], 'limit' => ['default' => 10]],
            'callback'            => [self::class, 'restSearch'],
        ]);
    }

    public static function restSearch(\WP_REST_Request $req)
    {
        $q      = (string) $req->get_param('q');
        $limit  = min(50, max(1, (int) $req->get_param('limit')));
        $engine = EngineManager::engine();
        $out    = [];
        foreach ($engine->query($q, $limit) as $h) {
            $id = (int) $h['doc_id'];
            $out[] = [
                'id'    => $id,
                'title' => html_entity_decode(wp_strip_all_tags(get_the_title($id))),
                'url'   => get_permalink($id),
                'score' => $h['score'],
                'thumb' => get_the_post_thumbnail_url($id, 'thumbnail') ?: null,
            ];
        }
        return rest_ensure_response([
            'query'        => $q,
            'count'        => count($out),
            'did_you_mean' => $engine->lastSuggestion(),
            'results'      => $out,
        ]);
    }
}
