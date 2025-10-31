<?php
/**
 * Data query builder for exports
 *
 * @package WooExporter\Export
 */

namespace WooExporter\Export;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data query class for building export SQL queries
 */
class DataQuery {
    /**
     * Get marketing export data (unique customers)
     *
     * IMPORTANT: The 'zgoda_marketingowa' field location is UNKNOWN.
     * Using placeholder 'TODO_FIND_MARKETING_CONSENT' - MUST BE REPLACED with actual meta_key or table join.
     *
     * @param array $filters Filters (start_date, end_date)
     * @param int $offset Pagination offset
     * @param int $limit Batch size
     * @return array Results array
     */
    public static function get_marketing_data(array $filters = [], int $offset = 0, int $limit = 500): array {
        global $wpdb;

        $where_clauses = ["p.post_type = 'shop_order'"];
        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')";

        // Apply date filters
        if (!empty($filters['start_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date >= %s", $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date <= %s", $filters['end_date'] . ' 23:59:59');
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "
            SELECT 
                MAX(pm_email.meta_value) as email,
                MAX(pm_first_name.meta_value) as first_name,
                MAX(pm_last_name.meta_value) as last_name,
                MAX(pm_consent.meta_value) as zgoda_marketingowa_raw,
                SUM(pm_total.meta_value) as total_spent,
                COUNT(DISTINCT p.ID) as order_count,
                MAX(p.post_date) as last_order_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
            LEFT JOIN {$wpdb->postmeta} pm_first_name ON p.ID = pm_first_name.post_id AND pm_first_name.meta_key = '_billing_first_name'
            LEFT JOIN {$wpdb->postmeta} pm_last_name ON p.ID = pm_last_name.post_id AND pm_last_name.meta_key = '_billing_last_name'
            LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            LEFT JOIN {$wpdb->postmeta} pm_consent ON p.ID = pm_consent.post_id AND pm_consent.meta_key = '_additional_terms'
            WHERE {$where_sql}
            AND pm_email.meta_value IS NOT NULL
            AND pm_email.meta_value != ''
            GROUP BY pm_email.meta_value
            ORDER BY last_order_date DESC
            LIMIT %d OFFSET %d
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
        
        // Parse serialized consent data
        foreach ($results as &$row) {
            $row['zgoda_marketingowa'] = self::parse_consent_field($row['zgoda_marketingowa_raw'] ?? '');
            unset($row['zgoda_marketingowa_raw']);
        }

        return $results;
    }

    /**
     * Parse consent field from serialized data
     *
     * @param string $raw_value Serialized PHP array or simple value
     * @return string Parsed consent value (tak/nie)
     */
    private static function parse_consent_field(string $raw_value): string {
        if (empty($raw_value)) {
            return '';
        }

        // Try to unserialize (it's a PHP serialized array)
        $data = @unserialize($raw_value);
        
        if ($data === false || !is_array($data)) {
            // If not serialized or failed, return as is
            return $raw_value;
        }

        // It's an array - look for consent field
        // Structure: a:1:{i:1;a:13:{s:4:"name";s:18:"Zgoda marketingowa";s:6:"status";s:1:"1";...}}
        foreach ($data as $item) {
            if (is_array($item) && isset($item['name'], $item['status'])) {
                // Check if this is the marketing consent
                if (stripos($item['name'], 'marketingowa') !== false || 
                    stripos($item['name'], 'zgoda') !== false ||
                    stripos($item['name'], 'consent') !== false) {
                    // Return status: 1 = tak, 0 = nie
                    return $item['status'] == '1' ? 'tak' : 'nie';
                }
            }
        }

        return '';
    }

    /**
     * Get total count for marketing export
     *
     * @param array $filters Filters (start_date, end_date)
     * @return int Total count
     */
    public static function get_marketing_count(array $filters = []): int {
        global $wpdb;

        $where_clauses = ["p.post_type = 'shop_order'"];
        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')";

        if (!empty($filters['start_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date >= %s", $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date <= %s", $filters['end_date'] . ' 23:59:59');
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "
            SELECT COUNT(DISTINCT pm_email.meta_value) as total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
            WHERE {$where_sql}
            AND pm_email.meta_value IS NOT NULL
            AND pm_email.meta_value != ''
        ";

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get analytics export data (detailed line items)
     *
     * IMPORTANT: The 'zgoda_marketingowa' field location is UNKNOWN.
     * Using placeholder 'TODO_FIND_MARKETING_CONSENT' - MUST BE REPLACED.
     *
     * @param array $filters Filters (start_date, end_date)
     * @param int $offset Pagination offset
     * @param int $limit Batch size
     * @return array Results array
     */
    public static function get_analytics_data(array $filters = [], int $offset = 0, int $limit = 500): array {
        global $wpdb;

        $where_clauses = ["p.post_type = 'shop_order'"];
        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled')";

        if (!empty($filters['start_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date >= %s", $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date <= %s", $filters['end_date'] . ' 23:59:59');
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "
            SELECT 
                p.ID as order_id,
                p.post_date as order_date,
                p.post_status as order_status,
                pm_total.meta_value as order_total,
                pm_currency.meta_value as order_currency,
                pm_email.meta_value as billing_email,
                pm_phone.meta_value as billing_phone,
                CONCAT(pm_first_name.meta_value, ' ', pm_last_name.meta_value) as billing_full_name,
                pm_city.meta_value as billing_city,
                pm_postcode.meta_value as billing_postcode,
                pm_customer_id.meta_value as user_id,
                oi.order_item_name as item_name,
                oim_qty.meta_value as item_quantity,
                oim_total.meta_value as item_total,
                pm_coupons.meta_value as coupons_used,
                pm_consent.meta_value as zgoda_marketingowa_raw
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
            LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            LEFT JOIN {$wpdb->postmeta} pm_currency ON p.ID = pm_currency.post_id AND pm_currency.meta_key = '_order_currency'
            LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
            LEFT JOIN {$wpdb->postmeta} pm_phone ON p.ID = pm_phone.post_id AND pm_phone.meta_key = '_billing_phone'
            LEFT JOIN {$wpdb->postmeta} pm_first_name ON p.ID = pm_first_name.post_id AND pm_first_name.meta_key = '_billing_first_name'
            LEFT JOIN {$wpdb->postmeta} pm_last_name ON p.ID = pm_last_name.post_id AND pm_last_name.meta_key = '_billing_last_name'
            LEFT JOIN {$wpdb->postmeta} pm_city ON p.ID = pm_city.post_id AND pm_city.meta_key = '_billing_city'
            LEFT JOIN {$wpdb->postmeta} pm_postcode ON p.ID = pm_postcode.post_id AND pm_postcode.meta_key = '_billing_postcode'
            LEFT JOIN {$wpdb->postmeta} pm_customer_id ON p.ID = pm_customer_id.post_id AND pm_customer_id.meta_key = '_customer_user'
            LEFT JOIN {$wpdb->postmeta} pm_coupons ON p.ID = pm_coupons.post_id AND pm_coupons.meta_key = '_used_coupons'
            LEFT JOIN {$wpdb->postmeta} pm_consent ON p.ID = pm_consent.post_id AND pm_consent.meta_key = '_additional_terms'
            WHERE {$where_sql}
            ORDER BY p.post_date DESC, oi.order_item_id ASC
            LIMIT %d OFFSET %d
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
        
        // Parse serialized consent data
        foreach ($results as &$row) {
            $row['zgoda_marketingowa'] = self::parse_consent_field($row['zgoda_marketingowa_raw'] ?? '');
            unset($row['zgoda_marketingowa_raw']);
        }

        return $results;
    }

    /**
     * Get total count for analytics export (count line items, not orders)
     *
     * @param array $filters Filters (start_date, end_date)
     * @return int Total count
     */
    public static function get_analytics_count(array $filters = []): int {
        global $wpdb;

        $where_clauses = ["p.post_type = 'shop_order'"];
        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled')";

        if (!empty($filters['start_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date >= %s", $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date <= %s", $filters['end_date'] . ' 23:59:59');
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "
            SELECT COUNT(*) as total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
            WHERE {$where_sql}
            AND oi.order_item_id IS NOT NULL
        ";

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get custom export data based on template
     *
     * @param int $template_id Template ID
     * @param array $filters Filters (start_date, end_date)
     * @param int $offset Pagination offset
     * @param int $limit Batch size
     * @return array Results array
     */
    public static function get_custom_data(int $template_id, array $filters = [], int $offset = 0, int $limit = 500): array {
        $template = \WooExporter\Database\Template::get($template_id);
        
        if (!$template || empty($template->selected_fields)) {
            return [];
        }

        global $wpdb;

        $where_clauses = ["p.post_type = 'shop_order'"];
        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled')";

        if (!empty($filters['start_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date >= %s", $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date <= %s", $filters['end_date'] . ' 23:59:59');
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Build dynamic SELECT and JOINs
        $select_parts = [];
        $joins = [];
        $join_counter = 0;

        foreach ($template->selected_fields as $field) {
            // Handle basic order fields (from posts table, not meta)
            if ($field === 'order_id') {
                $select_parts[] = "p.ID as order_id";
            } elseif ($field === 'order_date') {
                $select_parts[] = "p.post_date as order_date";
            } elseif ($field === 'order_status') {
                $select_parts[] = "p.post_status as order_status";
            } elseif ($field === 'order_total') {
                $alias = 'pm_total';
                $select_parts[] = "{$alias}.meta_value as order_total";
                $joins[] = "LEFT JOIN {$wpdb->postmeta} {$alias} ON p.ID = {$alias}.post_id AND {$alias}.meta_key = '_order_total'";
            } elseif (strpos($field, '__') !== false) {
                // Virtual field (parent__subkey) - select parent field, will parse later
                $parts = explode('__', $field, 2);
                $parent_key = $parts[0];
                $alias = 'pm_' . $join_counter;
                $select_parts[] = "{$alias}.meta_value as `{$field}`";
                $joins[] = "LEFT JOIN {$wpdb->postmeta} {$alias} ON p.ID = {$alias}.post_id AND {$alias}.meta_key = '{$parent_key}'";
                $join_counter++;
            } else {
                // Regular meta fields
                $alias = 'pm_' . $join_counter;
                $select_parts[] = "{$alias}.meta_value as `{$field}`";
                $joins[] = "LEFT JOIN {$wpdb->postmeta} {$alias} ON p.ID = {$alias}.post_id AND {$alias}.meta_key = '{$field}'";
                $join_counter++;
            }
        }

        $select_sql = implode(', ', $select_parts);
        $joins_sql = implode(' ', $joins);

        $sql = "
            SELECT {$select_sql}
            FROM {$wpdb->posts} p
            {$joins_sql}
            WHERE {$where_sql}
            ORDER BY p.post_date DESC
            LIMIT %d OFFSET %d
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
        
        // Parse virtual fields and consent fields
        foreach ($results as &$row) {
            foreach ($template->selected_fields as $field) {
                // Virtual field - extract from serialized parent
                if (strpos($field, '__') !== false && isset($row[$field])) {
                    $row[$field] = MetaScanner::extract_virtual_field($field, $row);
                }
                // Legacy: Parse _additional_terms if selected as whole field
                elseif ($field === '_additional_terms' && !empty($row[$field])) {
                    $row[$field] = self::parse_consent_field($row[$field]);
                }
            }
        }

        return $results;
    }

    /**
     * Get total count for custom export
     *
     * @param int $template_id Template ID
     * @param array $filters Filters
     * @return int Total count
     */
    public static function get_custom_count(int $template_id, array $filters = []): int {
        global $wpdb;

        $where_clauses = ["p.post_type = 'shop_order'"];
        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled')";

        if (!empty($filters['start_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date >= %s", $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $where_clauses[] = $wpdb->prepare("p.post_date <= %s", $filters['end_date'] . ' 23:59:59');
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "
            SELECT COUNT(*) as total
            FROM {$wpdb->posts} p
            WHERE {$where_sql}
        ";

        return (int) $wpdb->get_var($sql);
    }
}


