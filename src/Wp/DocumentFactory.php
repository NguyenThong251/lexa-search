<?php

namespace Lexa\Wp;

use Lexa\Engine\Document;

/**
 * Turns a WP_Post into a normalized Document (zone => text). Generic for any
 * post type; adds WooCommerce product fields (SKU, categories) when present.
 */
final class DocumentFactory
{
    public static function fromPost(\WP_Post $post): Document
    {
        $zones = [
            'title'   => (string) $post->post_title,
            'content' => trim(wp_strip_all_tags((string) $post->post_content) . ' ' . (string) $post->post_excerpt),
        ];

        if ($post->post_type === 'product') {
            $codes = [];
            foreach (['_sku_v2', '_sku'] as $key) {
                $value = trim((string) get_post_meta($post->ID, $key, true));
                if ($value !== '' && !preg_match('#^https?://#i', $value)) {
                    $codes[] = $value;
                }
            }
            if ($codes) {
                $zones['sku'] = implode(' ', array_unique($codes));
            }
            $cats = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']);
            if (is_array($cats) && $cats) {
                $zones['attr'] = implode(' ', $cats);
            }
        }

        $zones = array_filter($zones, static fn($v) => $v !== '' && $v !== null);
        return Document::make($post->ID, $zones);
    }
}
