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
     * @return array Grouped meta fields (includes flattened serialized fields)
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

        // Flatten serialized fields
        $flattened_keys = self::flatten_serialized_fields($meta_keys);

        return self::group_meta_keys($flattened_keys);
    }

    /**
     * Flatten serialized fields into individual sub-fields
     *
     * @param array $meta_keys Original meta keys
     * @return array Expanded meta keys (includes virtual fields)
     */
    private static function flatten_serialized_fields(array $meta_keys): array {
        global $wpdb;
        
        $flattened = [];
        $serialized_candidates = ['_additional_terms', '_woo_fakturownia_faktura', 'pys_enrich_data'];

        foreach ($meta_keys as $key) {
            $flattened[] = $key;
            
            // Check if this is a known serialized field
            if (in_array($key, $serialized_candidates)) {
                // Get sample value to inspect structure
                $sample = $wpdb->get_var($wpdb->prepare("
                    SELECT pm.meta_value
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE p.post_type = 'shop_order'
                    AND pm.meta_key = %s
                    AND pm.meta_value IS NOT NULL
                    AND pm.meta_value != ''
                    ORDER BY p.post_date DESC
                    LIMIT 1
                ", $key));

                if ($sample) {
                    $parsed = @unserialize($sample);
                    
                    if (is_array($parsed)) {
                        // Create virtual fields for each sub-key
                        foreach ($parsed as $sub_key => $sub_value) {
                            if (is_array($sub_value)) {
                                // Handle nested arrays (like checkout fields)
                                if (isset($sub_value['name'])) {
                                    // Create field for this checkbox
                                    $virtual_field = $key . '__' . sanitize_key($sub_value['name']);
                                    $flattened[] = $virtual_field;
                                } else {
                                    // Generic nested array
                                    foreach (array_keys($sub_value) as $nested_key) {
                                        $virtual_field = $key . '__' . $sub_key . '__' . $nested_key;
                                        $flattened[] = $virtual_field;
                                    }
                                }
                            } else {
                                // Simple key-value
                                $virtual_field = $key . '__' . $sub_key;
                                $flattened[] = $virtual_field;
                            }
                        }
                    }
                }
            }
        }

        return array_unique($flattened);
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

        // Add basic order fields first
        $grouped['order'] = ['order_id', 'order_date', 'order_status', 'order_total'];
        
        foreach ($meta_keys as $key) {
            // Billing fields
            if (substr($key, 0, 9) === '_billing_') {
                $grouped['billing'][] = $key;
            }
            // Shipping fields
            elseif (substr($key, 0, 10) === '_shipping_') {
                $grouped['shipping'][] = $key;
            }
            // Payment fields
            elseif (strpos($key, 'payment') !== false || strpos($key, 'transaction') !== false) {
                $grouped['payment'][] = $key;
            }
            // WooCommerce internal
            elseif (substr($key, 0, 7) === '_order_' || substr($key, 0, 4) === '_wc_' || substr($key, 0, 3) === 'wc_') {
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
     * @param array $meta_keys Meta keys to fetch (if empty, fetch ALL)
     * @return array Meta key => value pairs
     */
    public static function get_sample_values(int $order_id, array $meta_keys = []): array {
        global $wpdb;

        // Get order basic data
        $order = $wpdb->get_row($wpdb->prepare("
            SELECT ID as order_id, post_date as order_date, post_status as order_status
            FROM {$wpdb->posts}
            WHERE ID = %d AND post_type = 'shop_order'
        ", $order_id), ARRAY_A);

        if (!$order) {
            return [];
        }

        $samples = [
            'order_id' => $order['order_id'],
            'order_date' => $order['order_date'],
            'order_status' => str_replace('wc-', '', $order['order_status'])
        ];

        // Get order_total from meta
        $order_total = $wpdb->get_var($wpdb->prepare("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE post_id = %d AND meta_key = '_order_total'
        ", $order_id));
        
        $samples['order_total'] = $order_total ?: '';

        // Fetch ALL meta
        $all_meta = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id = %d
            ORDER BY meta_key
        ", $order_id));

        $raw_meta = [];
        foreach ($all_meta as $row) {
            $raw_meta[$row->meta_key] = $row->meta_value;
        }

        // If specific keys requested
        if (!empty($meta_keys)) {
            foreach ($meta_keys as $key) {
                // Check if it's a virtual field (parent__subkey)
                if (strpos($key, '__') !== false) {
                    $samples[$key] = self::extract_virtual_field($key, $raw_meta);
                } elseif (isset($raw_meta[$key])) {
                    $samples[$key] = $raw_meta[$key];
                } else {
                    $samples[$key] = '';
                }
            }
        } else {
            // Preview mode - add flattened virtual fields
            foreach ($raw_meta as $key => $value) {
                $samples[$key] = $value;
                
                // Try to flatten serialized
                $unserialized = @unserialize($value);
                if (is_array($unserialized)) {
                    foreach ($unserialized as $sub_key => $sub_value) {
                        if (is_array($sub_value) && isset($sub_value['name'])) {
                            $virtual_key = $key . '__' . sanitize_key($sub_value['name']);
                            $samples[$virtual_key] = $sub_value['status'] == '1' ? 'tak' : 'nie';
                        } elseif (!is_array($sub_value)) {
                            $virtual_key = $key . '__' . $sub_key;
                            $samples[$virtual_key] = $sub_value;
                        }
                    }
                }
            }
        }

        return $samples;
    }

    /**
     * Extract value from virtual field (parent__subkey)
     *
     * @param string $virtual_field Virtual field name (e.g., _additional_terms__zgoda_marketingowa)
     * @param array $raw_meta Raw meta data
     * @return string Extracted value
     */
    private static function extract_virtual_field(string $virtual_field, array $raw_meta): string {
        $parts = explode('__', $virtual_field, 2);
        if (count($parts) !== 2) {
            return '';
        }

        list($parent_key, $sub_key) = $parts;

        if (!isset($raw_meta[$parent_key])) {
            return '';
        }

        $unserialized = @unserialize($raw_meta[$parent_key]);
        if (!is_array($unserialized)) {
            return '';
        }

        // Search for the sub_key
        foreach ($unserialized as $item) {
            if (is_array($item) && isset($item['name'])) {
                $sanitized_name = sanitize_key($item['name']);
                if ($sanitized_name === $sub_key) {
                    return $item['status'] == '1' ? 'tak' : 'nie';
                }
            }
        }

        // Direct key access
        if (isset($unserialized[$sub_key])) {
            return is_string($unserialized[$sub_key]) ? $unserialized[$sub_key] : json_encode($unserialized[$sub_key]);
        }

        return '';
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
     * Parse serialized data for preview
     *
     * @param string $value Raw value
     * @return array Parsed info [is_serialized, parsed_data, display]
     */
    public static function parse_for_preview(string $value): array {
        // Check if serialized
        $unserialized = @unserialize($value);
        
        if ($unserialized === false || !is_array($unserialized)) {
            return [
                'is_serialized' => false,
                'parsed_data' => null,
                'display' => $value
            ];
        }

        // It's serialized - create human-readable display
        $items = [];
        
        foreach ($unserialized as $key => $item) {
            if (is_array($item)) {
                // Format like: Zgoda marketingowa: TAK (status=1)
                if (isset($item['name']) && isset($item['status'])) {
                    $status_text = $item['status'] == '1' ? 'TAK' : 'NIE';
                    $items[] = $item['name'] . ': ' . $status_text;
                } else {
                    // Generic array display
                    $items[] = json_encode($item, JSON_UNESCAPED_UNICODE);
                }
            } else {
                $items[] = $key . ': ' . $item;
            }
        }

        return [
            'is_serialized' => true,
            'parsed_data' => $unserialized,
            'display' => implode(' | ', $items)
        ];
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

