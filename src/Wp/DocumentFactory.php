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
            $sku = get_post_meta($post->ID, '_sku', true);
            if ($sku !== '' && $sku !== false) {
                $zones['sku'] = (string) $sku;
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
