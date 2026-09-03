<?php

namespace Lexa;

use Lexa\Wp\Admin\AdminPage;
use Lexa\Wp\Cli\Commands;
use Lexa\Wp\Environment;
use Lexa\Wp\QueryIntegration;
use Lexa\Wp\SyncSubscriber;
use Lexa\Wp\WpdbIndexStore;

/**
 * Plugin bootstrap / wiring. Pure-PHP cores (Lexa\Analysis, Lexa\Engine) stay
 * WordPress-free; everything WP-coupled lives under Lexa\Wp.
 */
final class Plugin
{
    /** Create the index tables on activation. */
    public static function activate(): void
    {
        global $wpdb;
        (new WpdbIndexStore($wpdb))->install();
    }

    public static function boot(): void
    {
        load_plugin_textdomain('lexa-search', false, dirname(plugin_basename(LEXA_FILE)) . '/languages');

        if (is_admin()) {
            AdminPage::register();
            Environment::register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('lexa', Commands::class);
        }
        // Auto-index on change (save/term/Woo/stock/trash/delete → Action Scheduler).
        SyncSubscriber::register();

        // Front-end product search → engine (self-guards: main query, product
        // search, kill switch + index-ready flag).
        QueryIntegration::register();
    }
}
