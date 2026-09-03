<?php

namespace Lexa\Wp;

/**
 * Auto-index on change. Save/term/Woo/stock/price/trash/delete hooks enqueue an
 * Action Scheduler job per affected post (coalesced once per request). The
 * actual indexing runs in the `lexa_index_post` handler — drained by Action
 * Scheduler's own async runner, by `wp lexa run`, or by the admin "Process
 * pending now" button.
 *
 * If Action Scheduler is unavailable (no WooCommerce), it falls back to inline
 * indexing so the plugin still works on a plain WordPress site.
 */
final class SyncSubscriber
{
    public const HOOK  = 'lexa_index_post';
    public const GROUP = 'lexa';

    /** @var array<int,bool> per-request dedup (coalesces multiple hooks for one post in one request) */
    private static array $seen = [];

    /** Clear the per-request dedup. Real web requests reset naturally; long-running
     *  CLI/daemon processes that edit the same post again should call this. */
    public static function resetRequestCache(): void
    {
        self::$seen = [];
    }

    public static function register(): void
    {
        // run the queued job
        add_action(self::HOOK, ['Lexa\\Wp\\Indexer', 'handle'], 10, 1);

        // upserts
        add_action('wp_after_insert_post', [self::class, 'onSave'], 20, 1);
        add_action('woocommerce_update_product', [self::class, 'onSave'], 20, 1);
        add_action('woocommerce_new_product', [self::class, 'onSave'], 20, 1);
        add_action('set_object_terms', [self::class, 'onTerms'], 20, 1);
        add_action('woocommerce_product_set_stock', [self::class, 'onProductObject'], 20, 1);
        add_action('woocommerce_variation_set_stock', [self::class, 'onProductObject'], 20, 1);
        add_action('woocommerce_updated_product_price', [self::class, 'onSave'], 20, 1);

        // removals
        add_action('trashed_post', [self::class, 'onRemove'], 20, 1);
        add_action('untrashed_post', [self::class, 'onSave'], 20, 1);
        add_action('deleted_post', [self::class, 'onRemove'], 20, 1);
    }

    public static function onSave($postId): void
    {
        $id = (int) $postId;
        if ($id <= 0 || wp_is_post_revision($id) || wp_is_post_autosave($id)) {
            return;
        }
        // only upsert types we index (removal hooks bypass this)
        if (!in_array(get_post_type($id), Settings::postTypes(), true)) {
            return;
        }
        self::enqueue($id);
    }

    public static function onTerms($objectId): void
    {
        self::onSave($objectId);
    }

    public static function onProductObject($product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            self::enqueue((int) $product->get_id());
        }
    }

    public static function onRemove($postId): void
    {
        self::enqueue((int) $postId); // handler removes it (post gone/trashed)
    }

    private static function enqueue(int $id): void
    {
        if ($id <= 0 || isset(self::$seen[$id])) {
            return;
        }
        self::$seen[$id] = true;

        if (function_exists('as_enqueue_async_action')) {
            if (!as_has_scheduled_action(self::HOOK, [$id], self::GROUP)) {
                as_enqueue_async_action(self::HOOK, [$id], self::GROUP);
            }
        } else {
            Indexer::indexPost($id); // no Action Scheduler → index inline
        }
    }
}
