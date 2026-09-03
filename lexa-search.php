<?php
/**
 * Plugin Name:       Lexa Search
 * Plugin URI:        https://quocduy.com.vn
 * Description:       Multilingual, mixed-language search (Vietnamese + English + model codes) for WordPress and WooCommerce — per-token language routing, diacritic-insensitive, BM25F relevance, self-hosted on MySQL.
 * Version:           0.4.2
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Author:            quocduydev
 * Author URI:        https://quocduy.com.vn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lexa-search
 *
 * Lexa Search — Copyright (c) 2026 quocduydev (https://quocduy.com.vn)
 * Licensed under the GPL v2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LEXA_VERSION', '0.4.2');
define('LEXA_FILE', __FILE__);
define('LEXA_DIR', plugin_dir_path(__FILE__));
define('LEXA_URL', plugin_dir_url(__FILE__));

require_once LEXA_DIR . 'bootstrap.php'; // PSR-4 autoloader (WP-agnostic)

register_activation_hook(__FILE__, ['Lexa\\Plugin', 'activate']);
add_action('plugins_loaded', ['Lexa\\Plugin', 'boot']);

/*
 * -----------------------------------------------------------------------------
 * Self-hosted updates — GitHub Releases
 * -----------------------------------------------------------------------------
 * Puts a normal "update now" notice on the Plugins screen. Release process and
 * the full list of ways this can silently break: see RELEASING.md.
 *
 * The repo is public, so NO token is needed — update checks work out of the box.
 * A token is only worth setting on a host that hits GitHub's 60-requests-per-hour
 * unauthenticated limit. If you do set one, put it in wp-config.php, never in
 * this file — anything here is committed to git and copied into every backup:
 *
 *     define('LEXA_GITHUB_TOKEN', '<token>');
 */
add_action('plugins_loaded', 'lexa_init_update_checker', 1);
function lexa_init_update_checker() {
    // Update checks only ever matter in wp-admin and during cron. Skipping the
    // front end keeps this off every cached page request.
    if (!is_admin() && !(defined('DOING_CRON') && DOING_CRON)) {
        return;
    }

    // plugin-update-checker.php uses a bare `require` internally, so this outer
    // call must be require_once. The v5\PucFactory alias only exists because
    // that file loads it — it is NOT reachable via autoload.
    require_once LEXA_DIR . 'plugin-update-checker/plugin-update-checker.php';

    $checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        // Must be exactly two path segments. Appending /tree/main, a .git
        // suffix or a www. prefix makes PUC fall back to a non-GitHub checker
        // (or 404 forever) — in both cases silently, with no update ever found.
        'https://github.com/NguyenThong251/lexa-search/',
        LEXA_FILE,
        'lexa-search'
    );

    // PUC defaults to "master"; GitHub creates new repos with "main". Without
    // this, update detection never runs. On main/master PUC tries the latest
    // Release first, then the highest tag, then the branch itself.
    $checker->setBranch('main');

    // Private repo: with no token every API call 404s and PUC reports
    // "no update available" forever, without surfacing an error.
    if (defined('LEXA_GITHUB_TOKEN') && LEXA_GITHUB_TOKEN) {
        $checker->setAuthentication(LEXA_GITHUB_TOKEN);
    }
}

/*
 * PUC reports API failures (bad/expired token, repo renamed, rate limit) only
 * through this action — the Plugins screen shows nothing. Log them so a broken
 * update channel is diagnosable instead of looking like "no updates".
 */
add_action('puc_api_error', function ($error, $response = null, $url = null, $slug = null) {
    if ($slug === 'lexa-search' && is_wp_error($error)) {
        error_log('[lexa-search] update check failed: ' . $error->get_error_code()
            . ' — ' . $error->get_error_message());
    }
}, 10, 4);
