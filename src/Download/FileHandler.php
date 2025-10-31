<?php
/**
 * Secure file download handler
 *
 * @package WooExporter\Download
 */

namespace WooExporter\Download;

use WooExporter\Database\Job;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * File download handler with security checks
 */
class FileHandler {
    /**
     * Constructor - register download action
     */
    public function __construct() {
        // Register download action (for both logged in and logged out users)
        add_action('wp_ajax_woo_exporter_download', [$this, 'handle_download']);
        add_action('wp_ajax_nopriv_woo_exporter_download', [$this, 'handle_download_nopriv']);
    }

    /**
     * Handle download request for logged-in users
     */
    public function handle_download(): void {
        $this->process_download(true);
    }

    /**
     * Handle download request for non-logged-in users (denied)
     */
    public function handle_download_nopriv(): void {
        wp_die(
            __('Musisz być zalogowany, aby pobrać plik.', 'woo-data-exporter'),
            __('Brak dostępu', 'woo-data-exporter'),
            ['response' => 403]
        );
    }

    /**
     * Process download with security checks
     *
     * @param bool $user_logged_in Whether user is logged in
     */
    private function process_download(bool $user_logged_in = true): void {
        // Get parameters
        $job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
        $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';

        if (!$job_id || !$hash) {
            wp_die(
                __('Nieprawidłowe parametry pobierania.', 'woo-data-exporter'),
                __('Błąd', 'woo-data-exporter'),
                ['response' => 400]
            );
        }

        // Get job from database
        $job = Job::get($job_id);

        if (!$job) {
            wp_die(
                __('Zadanie eksportu nie zostało znalezione.', 'woo-data-exporter'),
                __('Nie znaleziono', 'woo-data-exporter'),
                ['response' => 404]
            );
        }

        // Verify hash (security check)
        if ($job->file_url_hash !== $hash) {
            wp_die(
                __('Nieprawidłowy link pobierania.', 'woo-data-exporter'),
                __('Brak dostępu', 'woo-data-exporter'),
                ['response' => 403]
            );
        }

        // Check if user has permission
        if (!$this->user_can_download($job)) {
            wp_die(
                __('Nie masz uprawnień do pobrania tego pliku.', 'woo-data-exporter'),
                __('Brak dostępu', 'woo-data-exporter'),
                ['response' => 403]
            );
        }

        // Check if job is completed
        if ($job->status !== Job::STATUS_COMPLETED) {
            wp_die(
                __('Eksport nie został jeszcze ukończony.', 'woo-data-exporter'),
                __('Plik niedostępny', 'woo-data-exporter'),
                ['response' => 400]
            );
        }

        // Check if file exists
        if (!file_exists($job->file_path)) {
            wp_die(
                __('Plik nie został znaleziony na serwerze.', 'woo-data-exporter'),
                __('Nie znaleziono pliku', 'woo-data-exporter'),
                ['response' => 404]
            );
        }

        // Check file age (7 days expiration)
        if ($this->is_file_expired($job)) {
            wp_die(
                __('Link pobierania wygasł. Pliki są dostępne przez 7 dni.', 'woo-data-exporter'),
                __('Link wygasł', 'woo-data-exporter'),
                ['response' => 410]
            );
        }

        // Serve the file
        $this->serve_file($job->file_path);
    }

    /**
     * Check if user can download the file
     *
     * @param object $job Job object
     * @return bool True if user can download
     */
    private function user_can_download(object $job): bool {
        $current_user_id = get_current_user_id();

        // Admin can download everything
        if (current_user_can('manage_options')) {
            return true;
        }

        // User can download their own exports
        if ($current_user_id === (int) $job->requester_id) {
            return true;
        }

        // Shop managers can download
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        return false;
    }

    /**
     * Check if file is expired (older than 7 days)
     *
     * @param object $job Job object
     * @return bool True if expired
     */
    private function is_file_expired(object $job): bool {
        if (!$job->completed_at) {
            return false;
        }

        $completed_timestamp = strtotime($job->completed_at);
        $expiration_days = apply_filters('woo_exporter_file_expiration_days', 7);
        $expiration_timestamp = $completed_timestamp + ($expiration_days * DAY_IN_SECONDS);

        return time() > $expiration_timestamp;
    }

    /**
     * Serve file for download
     *
     * @param string $file_path Path to file
     */
    private function serve_file(string $file_path): void {
        // Clear output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Get file info
        $filename = basename($file_path);
        $filesize = filesize($file_path);

        // Set headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Read and output file
        readfile($file_path);
        exit;
    }

    /**
     * Clean up expired files (can be called by cron)
     */
    public static function cleanup_expired_files(): void {
        global $wpdb;
        
        $expiration_days = apply_filters('woo_exporter_file_expiration_days', 7);
        $table_name = \WooExporter\Database\Schema::get_table_name();

        // Get expired completed jobs
        $expired_jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, file_path FROM {$table_name} 
                WHERE status = %s 
                AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                AND file_path IS NOT NULL",
                Job::STATUS_COMPLETED,
                $expiration_days
            )
        );

        foreach ($expired_jobs as $job) {
            // Delete file
            if (file_exists($job->file_path)) {
                unlink($job->file_path);
            }

            // Optionally delete job record
            // Job::delete_old_jobs() handles this
        }
    }
}

