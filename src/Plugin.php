<?php

namespace Lexa;

use Lexa\Wp\Admin\AdminPage;
use Lexa\Wp\Cli\Commands;
use Lexa\Wp\Environment;
use Lexa\Wp\QueryIntegration;
use Lexa\Wp\Settings;
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

    /** Option holding the version the stored data was last migrated to. */
    public const VERSION_OPTION = 'lexa_data_version';

    /**
     * Repair stored data after an upgrade. WordPress does not fire activation
     * hooks on update, so this runs on boot and is a no-op once it has caught up.
     */
    public static function migrate(): void
    {
        $from = (string) get_option(self::VERSION_OPTION, '');

        // 0.3.0's Settings form had no "enabled" checkbox, but its sanitizer read
        // one — so every Save silently stored enabled=false and handed search
        // back to WordPress's LIKE. There was no way to switch it off on purpose
        // back then, so a stored false can only be that bug: undo it once.
        if ($from === '') {
            $stored = get_option(Settings::OPTION);
            if (is_array($stored) && array_key_exists('enabled', $stored) && !$stored['enabled']) {
                $stored['enabled'] = true;
                update_option(Settings::OPTION, $stored);
            }
        }

        if ($from !== LEXA_VERSION) {
            update_option(self::VERSION_OPTION, LEXA_VERSION);
        }
    }

    public static function boot(): void
    {
        load_plugin_textdomain('lexa-search', false, dirname(plugin_basename(LEXA_FILE)) . '/languages');

        if (is_admin()) {
            self::migrate();
        }

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
