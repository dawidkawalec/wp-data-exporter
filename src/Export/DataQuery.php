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

        // TODO: Replace 'TODO_FIND_MARKETING_CONSENT' with actual field location
        // Possible locations:
        // - wp_postmeta (order meta): pm_consent.meta_value WHERE pm_consent.meta_key = 'zgoda_marketingowa'
        // - wp_usermeta (user meta): um.meta_value WHERE um.meta_key = 'zgoda_marketingowa'
        // - Custom table from another plugin
        $sql = "
            SELECT 
                MAX(pm_email.meta_value) as email,
                MAX(pm_first_name.meta_value) as first_name,
                MAX(pm_last_name.meta_value) as last_name,
                'TODO_FIND_MARKETING_CONSENT' as zgoda_marketingowa,
                SUM(pm_total.meta_value) as total_spent,
                COUNT(DISTINCT p.ID) as order_count,
                MAX(p.post_date) as last_order_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
            LEFT JOIN {$wpdb->postmeta} pm_first_name ON p.ID = pm_first_name.post_id AND pm_first_name.meta_key = '_billing_first_name'
            LEFT JOIN {$wpdb->postmeta} pm_last_name ON p.ID = pm_last_name.post_id AND pm_last_name.meta_key = '_billing_last_name'
            LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            WHERE {$where_sql}
            AND pm_email.meta_value IS NOT NULL
            AND pm_email.meta_value != ''
            GROUP BY pm_email.meta_value
            ORDER BY last_order_date DESC
            LIMIT %d OFFSET %d
        ";

        return $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
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

        // TODO: Replace 'TODO_FIND_MARKETING_CONSENT' with actual field location
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
                'TODO_FIND_MARKETING_CONSENT' as zgoda_marketingowa
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
            WHERE {$where_sql}
            ORDER BY p.post_date DESC, oi.order_item_id ASC
            LIMIT %d OFFSET %d
        ";

        return $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
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
}

