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
            notification_email VARCHAR(500) NULL COMMENT 'Email(e) do powiadomień (oddzielone przecinkami)',
            schedule_id BIGINT(20) UNSIGNED NULL COMMENT 'ID harmonogramu (jeśli auto-generowane)',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL COMMENT 'Data i czas zakończenia zadania',
            PRIMARY KEY (id),
            INDEX status_idx (status),
            INDEX job_type_idx (job_type),
            INDEX requester_id_idx (requester_id),
            INDEX schedule_id_idx (schedule_id),
            INDEX created_at_idx (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Create schedules table
        $schedules_table = $wpdb->prefix . 'export_schedules';
        $sql_schedules = "CREATE TABLE IF NOT EXISTS {$schedules_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL COMMENT 'Nazwa harmonogramu',
            job_type VARCHAR(50) NOT NULL COMMENT 'Typ eksportu',
            frequency_type VARCHAR(20) NOT NULL COMMENT 'daily, weekly, monthly',
            frequency_value INT UNSIGNED NOT NULL COMMENT 'Co ile dni / który dzień tygodnia / miesiąca',
            start_date DATE NOT NULL COMMENT 'Data rozpoczęcia',
            next_run_date DATETIME NOT NULL COMMENT 'Kiedy następne uruchomienie',
            notification_email VARCHAR(500) NOT NULL COMMENT 'Email(e) do powiadomień',
            filters JSON NULL COMMENT 'Filtry dla eksportu',
            is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Czy aktywny (1) czy zapauzowany (0)',
            created_by BIGINT(20) UNSIGNED NOT NULL COMMENT 'ID użytkownika twórcy',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_run_at DATETIME NULL COMMENT 'Ostatnie uruchomienie',
            PRIMARY KEY (id),
            INDEX next_run_idx (next_run_date, is_active),
            INDEX created_by_idx (created_by),
            INDEX is_active_idx (is_active)
        ) {$charset_collate};";
        
        dbDelta($sql_schedules);

        // Create templates table
        $templates_table = $wpdb->prefix . 'export_templates';
        $sql_templates = "CREATE TABLE IF NOT EXISTS {$templates_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL COMMENT 'Nazwa szablonu',
            description TEXT NULL COMMENT 'Opis szablonu',
            selected_fields JSON NOT NULL COMMENT 'Wybrane pola do eksportu (array)',
            field_aliases JSON NULL COMMENT 'Aliasy kolumn (meta_key => alias)',
            field_order JSON NULL COMMENT 'Kolejność kolumn (array of meta_keys)',
            is_global TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Globalny (1) czy per-user (0)',
            created_by BIGINT(20) UNSIGNED NOT NULL COMMENT 'ID twórcy',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX created_by_idx (created_by),
            INDEX is_global_idx (is_global)
        ) {$charset_collate};";
        
        dbDelta($sql_templates);

        // Store database version
        update_option('woo_exporter_db_version', WOO_EXPORTER_VERSION);
    }

    /**
     * Get schedules table name
     */
    public static function get_schedules_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'export_schedules';
    }

    /**
     * Get templates table name
     */
    public static function get_templates_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'export_templates';
    }

    /**
     * Drop plugin tables (for uninstall)
     */
    public static function drop_tables(): void {
        global $wpdb;
        $jobs_table = self::get_table_name();
        $schedules_table = self::get_schedules_table_name();
        $templates_table = self::get_templates_table_name();
        
        $wpdb->query("DROP TABLE IF EXISTS {$jobs_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$schedules_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$templates_table}");
        
        delete_option('woo_exporter_db_version');
    }
}

