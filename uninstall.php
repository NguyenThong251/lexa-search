<?php
/**
 * Uninstall — remove all plugin data (tables + options) when deleted from wp-admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (['lexa_postings', 'lexa_doc_zones', 'lexa_docs'] as $name) {
    $table = $wpdb->prefix . $name;
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB
}

foreach (['lexa_settings', 'lexa_ready', 'lexa_last_index', 'lexa_last_drain', 'lexa_env'] as $option) {
    delete_option($option);
}
