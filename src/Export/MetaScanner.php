<?php
/**
 * Meta Scanner - discovers all available meta fields from orders
 *
 * @package WooExporter\Export
 */

namespace WooExporter\Export;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta Scanner class
 */
class MetaScanner {
    /**
     * Scan all available meta fields from recent orders
     *
     * @param int $limit Number of orders to scan
     * @return array Grouped meta fields
     */
    public static function scan_available_fields(int $limit = 100): array {
        global $wpdb;

        // Get unique meta keys from recent orders
        $meta_keys = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            AND pm.meta_key NOT LIKE '\\_oembed\\_%'
            AND pm.meta_key NOT LIKE '\\_edit\\_%'
            ORDER BY pm.meta_key ASC
            LIMIT %d
        ", $limit * 10)); // *10 bo to unikalne klucze, nie rekordy

        return self::group_meta_keys($meta_keys);
    }

    /**
     * Group meta keys by category
     *
     * @param array $meta_keys Array of meta key strings
     * @return array Grouped array
     */
    private static function group_meta_keys(array $meta_keys): array {
        $grouped = [
            'order' => [],
            'billing' => [],
            'shipping' => [],
            'payment' => [],
            'woocommerce' => [],
            'custom' => []
        ];

        foreach ($meta_keys as $key) {
            // Billing fields
            if (str_starts_with($key, '_billing_')) {
                $grouped['billing'][] = $key;
            }
            // Shipping fields
            elseif (str_starts_with($key, '_shipping_')) {
                $grouped['shipping'][] = $key;
            }
            // Payment fields
            elseif (str_contains($key, 'payment') || str_contains($key, 'transaction')) {
                $grouped['payment'][] = $key;
            }
            // WooCommerce internal
            elseif (str_starts_with($key, '_order_') || str_starts_with($key, '_wc_') || str_starts_with($key, 'wc_')) {
                $grouped['woocommerce'][] = $key;
            }
            // Order basic
            elseif (in_array($key, ['_customer_user', '_customer_ip_address', '_created_via'])) {
                $grouped['order'][] = $key;
            }
            // Everything else
            else {
                $grouped['custom'][] = $key;
            }
        }

        // Remove empty groups
        return array_filter($grouped, function($group) {
            return !empty($group);
        });
    }

    /**
     * Get sample values for meta keys from specific order
     *
     * @param int $order_id Order ID
     * @param array $meta_keys Meta keys to fetch
     * @return array Meta key => value pairs
     */
    public static function get_sample_values(int $order_id, array $meta_keys): array {
        global $wpdb;

        if (empty($meta_keys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        
        $query = $wpdb->prepare("
            SELECT meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id = %d
            AND meta_key IN ({$placeholders})
        ", array_merge([$order_id], $meta_keys));

        $results = $wpdb->get_results($query);

        $samples = [];
        foreach ($results as $row) {
            $samples[$row->meta_key] = $row->meta_value;
        }

        // Add empty values for keys not found
        foreach ($meta_keys as $key) {
            if (!isset($samples[$key])) {
                $samples[$key] = '';
            }
        }

        return $samples;
    }

    /**
     * Get sample order IDs
     *
     * @param int $limit Number of orders
     * @return array Array of order IDs
     */
    public static function get_sample_order_ids(int $limit = 5): array {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare("
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status IN ('wc-completed', 'wc-processing')
            ORDER BY post_date DESC
            LIMIT %d
        ", $limit));
    }

    /**
     * Get human-readable label for meta key
     *
     * @param string $meta_key Meta key
     * @return string Human-readable label
     */
    public static function get_field_label(string $meta_key): string {
        // Remove prefixes
        $label = str_replace(['_billing_', '_shipping_', '_order_', '_wc_', 'wc_'], '', $meta_key);
        
        // Remove leading underscore
        $label = ltrim($label, '_');
        
        // Replace underscores with spaces
        $label = str_replace('_', ' ', $label);
        
        // Capitalize words
        $label = ucwords($label);

        return $label;
    }

    /**
     * Get category label
     *
     * @param string $category Category key
     * @return string Label
     */
    public static function get_category_label(string $category): string {
        $labels = [
            'order' => '🛒 Informacje o zamówieniu',
            'billing' => '📧 Dane rozliczeniowe',
            'shipping' => '📦 Dane wysyłki',
            'payment' => '💳 Płatność',
            'woocommerce' => '⚙️ WooCommerce',
            'custom' => '✨ Pola niestandardowe'
        ];

        return $labels[$category] ?? $category;
    }
}

