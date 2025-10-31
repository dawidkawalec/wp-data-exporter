<?php
/**
 * Uninstall script - runs when plugin is deleted
 *
 * @package WooExporter
 */

// Exit if not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Require the Schema class
require_once __DIR__ . '/vendor/autoload.php';

use WooExporter\Database\Schema;
use WooExporter\Database\Job;

// Remove database table (optional - comment out if you want to keep data)
// Schema::drop_tables();

// Remove scheduled cron events
$timestamp = wp_next_scheduled('woo_exporter_process_jobs');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'woo_exporter_process_jobs');
}

// Delete all export files
$upload_dir = WP_CONTENT_DIR . '/uploads/woo-exporter/';
if (file_exists($upload_dir)) {
    $files = glob($upload_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    // Remove directory
    @rmdir($upload_dir);
}

// Delete plugin options
delete_option('woo_exporter_db_version');

// Note: To completely remove the plugin:
// 1. Deactivate the plugin
// 2. Delete the plugin from WordPress admin
// This uninstall.php will run automatically

