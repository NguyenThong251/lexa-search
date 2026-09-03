<?php

namespace Lexa\Wp;

use Lexa\Wp\Admin\AdminPage;

/**
 * Environment probe — surfaces host conditions that affect the plugin
 * (admin notice on our pages + a Site Health test). Honest about what matters:
 * mbstring is required; missing intl is fine (we fold without it); WP-Cron off
 * needs a server cron for unattended auto-indexing.
 */
final class Environment
{
    /** @return array<int,array{0:string,1:string}> [level, message] */
    public static function checks(): array
    {
        $out = [];
        if (!extension_loaded('mbstring')) {
            $out[] = ['error', __('Lexa Search requires the PHP mbstring extension, which is not loaded. Search analysis will not work correctly.', 'lexa-search')];
        }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $out[] = ['warning', __('WP-Cron is disabled. Add a server cron running "wp lexa run" so auto-index jobs drain without manual action.', 'lexa-search')];
        }
        if (!extension_loaded('intl')) {
            $out[] = ['info', __('PHP intl is not present — Lexa Search uses its own diacritic folding, so this is fine.', 'lexa-search')];
        }
        return $out;
    }

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('site_status_tests', [self::class, 'siteHealth']);
    }

    public static function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, AdminPage::SLUG) === false) {
            return; // only nag on our own screens
        }
        foreach (self::checks() as [$level, $msg]) {
            if ($level === 'info') {
                continue;
            }
            $class = $level === 'error' ? 'notice-error' : 'notice-warning';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    public static function siteHealth(array $tests): array
    {
        $tests['direct']['lexa_search_env'] = [
            'label' => __('Lexa Search requirements', 'lexa-search'),
            'test'  => [self::class, 'siteHealthTest'],
        ];
        return $tests;
    }

    public static function siteHealthTest(): array
    {
        $ok = extension_loaded('mbstring');
        return [
            'label'       => $ok
                ? __('Lexa Search requirements are met', 'lexa-search')
                : __('Lexa Search is missing the mbstring extension', 'lexa-search'),
            'status'      => $ok ? 'good' : 'critical',
            'badge'       => ['label' => __('Search', 'lexa-search'), 'color' => $ok ? 'green' : 'red'],
            'description' => '<p>' . esc_html($ok
                ? __('PHP mbstring is available — multilingual analysis works.', 'lexa-search')
                : __('PHP mbstring is required for Lexa Search to analyze text.', 'lexa-search')) . '</p>',
            'test'        => 'lexa_search_env',
        ];
    }
}
