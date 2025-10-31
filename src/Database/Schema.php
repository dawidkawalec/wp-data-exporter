<?php
/**
 * Database schema management
 *
 * @package WooExporter\Database
 */

namespace WooExporter\Database;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database schema class
 */
class Schema {
    /**
     * Table name (without prefix)
     */
    public const TABLE_NAME = 'export_jobs';

    /**
     * Get full table name with WordPress prefix
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create plugin tables
     */
    public static function create_tables(): void {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(50) NOT NULL COMMENT 'Typ: marketing_export lub analytics_export',
            status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'Status: pending, processing, completed, failed',
            filters JSON NULL COMMENT 'Filtry eksportu (daty, itp.)',
            file_path VARCHAR(255) NULL COMMENT 'Ścieżka do wygenerowanego pliku',
            file_url_hash VARCHAR(64) NULL COMMENT 'Bezpieczny hash do generowania linku pobierania',
            error_message TEXT NULL COMMENT 'Komunikat błędu w przypadku niepowodzenia',
            processed_items INT UNSIGNED DEFAULT 0 COMMENT 'Liczba przetworzonych elementów',
            total_items INT UNSIGNED DEFAULT 0 COMMENT 'Całkowita liczba elementów do przetworzenia',
            requester_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'ID użytkownika, który zlecił zadanie',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL COMMENT 'Data i czas zakończenia zadania',
            PRIMARY KEY (id),
            INDEX status_idx (status),
            INDEX job_type_idx (job_type),
            INDEX requester_id_idx (requester_id),
            INDEX created_at_idx (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Store database version
        update_option('woo_exporter_db_version', WOO_EXPORTER_VERSION);
    }

    /**
     * Drop plugin tables (for uninstall)
     */
    public static function drop_tables(): void {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        delete_option('woo_exporter_db_version');
    }
}

