<?php
/**
 * Database migration helper
 *
 * @package WooExporter\Database
 */

namespace WooExporter\Database;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration class for updating database schema
 */
class Migration {
    /**
     * Run all pending migrations
     */
    public static function run(): void {
        $current_version = get_option('woo_exporter_db_version', '0.0.0');
        
        error_log('WOO_EXPORTER_MIGRATION: Current DB version: ' . $current_version);
        error_log('WOO_EXPORTER_MIGRATION: Target version: ' . WOO_EXPORTER_VERSION);

        // Always try to create/update tables (idempotent)
        self::ensure_tables_exist();
        
        // Update version
        update_option('woo_exporter_db_version', WOO_EXPORTER_VERSION);
        
        error_log('WOO_EXPORTER_MIGRATION: Migration completed');
    }

    /**
     * Ensure all tables exist with correct schema
     */
    private static function ensure_tables_exist(): void {
        global $wpdb;

        error_log('WOO_EXPORTER_MIGRATION: Ensuring tables exist...');

        // Check if export_jobs table exists
        $jobs_table = Schema::get_table_name();
        $jobs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$jobs_table}'") === $jobs_table;

        if (!$jobs_exists) {
            error_log('WOO_EXPORTER_MIGRATION: Creating jobs table...');
            Schema::create_tables();
        } else {
            error_log('WOO_EXPORTER_MIGRATION: Jobs table exists, checking columns...');
            self::ensure_jobs_columns();
        }

        // Check if schedules table exists
        $schedules_table = Schema::get_schedules_table_name();
        $schedules_exists = $wpdb->get_var("SHOW TABLES LIKE '{$schedules_table}'") === $schedules_table;

        if (!$schedules_exists) {
            error_log('WOO_EXPORTER_MIGRATION: Creating schedules table...');
            // Create it by re-running create_tables (it has IF NOT EXISTS)
            Schema::create_tables();
        } else {
            error_log('WOO_EXPORTER_MIGRATION: Schedules table exists');
        }

        // Check if templates table exists
        $templates_table = Schema::get_templates_table_name();
        $templates_exists = $wpdb->get_var("SHOW TABLES LIKE '{$templates_table}'") === $templates_table;

        if (!$templates_exists) {
            error_log('WOO_EXPORTER_MIGRATION: Creating templates table...');
            // Create it by re-running create_tables (it has IF NOT EXISTS)
            Schema::create_tables();
        } else {
            error_log('WOO_EXPORTER_MIGRATION: Templates table exists');
        }
    }

    /**
     * Ensure jobs table has all required columns
     */
    private static function ensure_jobs_columns(): void {
        global $wpdb;
        $table_name = Schema::get_table_name();

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Add notification_email if missing
        if (!in_array('notification_email', $columns)) {
            error_log('WOO_EXPORTER_MIGRATION: Adding notification_email column...');
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN notification_email VARCHAR(500) NULL COMMENT 'Email(e) do powiadomień (oddzielone przecinkami)' AFTER requester_id");
        }

        // Add schedule_id if missing
        if (!in_array('schedule_id', $columns)) {
            error_log('WOO_EXPORTER_MIGRATION: Adding schedule_id column...');
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN schedule_id BIGINT(20) UNSIGNED NULL COMMENT 'ID harmonogramu (jeśli auto-generowane)' AFTER notification_email");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX schedule_id_idx (schedule_id)");
        }
    }

    /**
     * Check database health
     */
    public static function check_health(): array {
        global $wpdb;

        $status = [];

        // Check jobs table
        $jobs_table = Schema::get_table_name();
        $status['jobs_table_exists'] = $wpdb->get_var("SHOW TABLES LIKE '{$jobs_table}'") === $jobs_table;
        
        if ($status['jobs_table_exists']) {
            $status['jobs_columns'] = $wpdb->get_col("DESCRIBE {$jobs_table}", 0);
            $status['jobs_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table}");
        }

        // Check schedules table
        $schedules_table = Schema::get_schedules_table_name();
        $status['schedules_table_exists'] = $wpdb->get_var("SHOW TABLES LIKE '{$schedules_table}'") === $schedules_table;
        
        if ($status['schedules_table_exists']) {
            $status['schedules_columns'] = $wpdb->get_col("DESCRIBE {$schedules_table}", 0);
            $status['schedules_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$schedules_table}");
        }

        return $status;
    }
}

