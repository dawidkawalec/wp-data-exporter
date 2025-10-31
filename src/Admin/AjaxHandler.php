<?php
/**
 * AJAX request handler
 *
 * @package WooExporter\Admin
 */

namespace WooExporter\Admin;

use WooExporter\Database\Job;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX Handler class
 */
class AjaxHandler {
    /**
     * Constructor - register AJAX actions
     */
    public function __construct() {
        add_action('wp_ajax_create_export_job', [$this, 'create_export_job']);
        add_action('wp_ajax_get_job_status', [$this, 'get_job_status']);
        add_action('wp_ajax_cancel_export_job', [$this, 'cancel_export_job']);
        add_action('wp_ajax_delete_export_job', [$this, 'delete_export_job']);
        add_action('wp_ajax_preview_export_csv', [$this, 'preview_export_csv']);
        add_action('wp_ajax_run_cron_manually', [$this, 'run_cron_manually']);
    }

    /**
     * Create new export job
     */
    public function create_export_job(): void {
        // Verify nonce
        if (!check_ajax_referer('woo_exporter_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy token bezpieczeństwa.', 'woo-data-exporter')
            ], 403);
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Nie masz uprawnień do tworzenia eksportów.', 'woo-data-exporter')
            ], 403);
        }

        // Get and validate export type
        $export_type = isset($_POST['export_type']) ? sanitize_text_field($_POST['export_type']) : '';
        
        if (!in_array($export_type, ['marketing', 'analytics'], true)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy typ eksportu.', 'woo-data-exporter')
            ], 400);
        }

        // Convert type to internal format
        $job_type = $export_type === 'marketing' ? Job::TYPE_MARKETING : Job::TYPE_ANALYTICS;

        // Get and validate filters
        $filters = [];
        
        if (isset($_POST['filters']) && is_string($_POST['filters'])) {
            $decoded_filters = json_decode(stripslashes($_POST['filters']), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $filters = $this->sanitize_filters($decoded_filters);
            }
        }

        // Direct filter parameters (fallback)
        if (isset($_POST['start_date']) && !empty($_POST['start_date'])) {
            $filters['start_date'] = sanitize_text_field($_POST['start_date']);
        }
        if (isset($_POST['end_date']) && !empty($_POST['end_date'])) {
            $filters['end_date'] = sanitize_text_field($_POST['end_date']);
        }

        // Validate dates
        if (!empty($filters['start_date']) && !$this->is_valid_date($filters['start_date'])) {
            wp_send_json_error([
                'message' => __('Nieprawidłowa data rozpoczęcia.', 'woo-data-exporter')
            ], 400);
        }
        if (!empty($filters['end_date']) && !$this->is_valid_date($filters['end_date'])) {
            wp_send_json_error([
                'message' => __('Nieprawidłowa data zakończenia.', 'woo-data-exporter')
            ], 400);
        }

        // Get notification email (optional)
        $notification_email = null;
        if (isset($_POST['notification_email']) && !empty($_POST['notification_email'])) {
            $emails = sanitize_text_field($_POST['notification_email']);
            // Validate emails
            $email_array = array_map('trim', explode(',', $emails));
            $valid_emails = array_filter($email_array, 'is_email');
            
            if (count($valid_emails) !== count($email_array)) {
                wp_send_json_error([
                    'message' => __('Jeden lub więcej adresów email jest nieprawidłowych.', 'woo-data-exporter')
                ], 400);
            }
            
            $notification_email = implode(',', $valid_emails);
        }

        // Create job
        $job_id = Job::create($job_type, $filters, get_current_user_id(), $notification_email);

        if (!$job_id) {
            wp_send_json_error([
                'message' => __('Nie udało się utworzyć zadania eksportu.', 'woo-data-exporter')
            ], 500);
        }

        wp_send_json_success([
            'message' => __('Zadanie eksportu zostało dodane do kolejki.', 'woo-data-exporter'),
            'job_id' => $job_id
        ]);
    }

    /**
     * Get job status
     */
    public function get_job_status(): void {
        // Verify nonce
        if (!check_ajax_referer('woo_exporter_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy token bezpieczeństwa.', 'woo-data-exporter')
            ], 403);
        }

        // Get job ID
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;

        if (!$job_id) {
            wp_send_json_error([
                'message' => __('Nieprawidłowe ID zadania.', 'woo-data-exporter')
            ], 400);
        }

        // Get job
        $job = Job::get($job_id);

        if (!$job) {
            wp_send_json_error([
                'message' => __('Zadanie nie zostało znalezione.', 'woo-data-exporter')
            ], 404);
        }

        // Check permissions
        if (!$this->user_can_view_job($job)) {
            wp_send_json_error([
                'message' => __('Nie masz uprawnień do przeglądania tego zadania.', 'woo-data-exporter')
            ], 403);
        }

        // Prepare response
        $response = [
            'id' => $job->id,
            'status' => $job->status,
            'job_type' => $job->job_type,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
            'processed_items' => $job->processed_items ?? 0,
            'total_items' => $job->total_items ?? 0,
            'progress_percent' => $this->calculate_progress($job)
        ];

        // Add download URL if completed
        if ($job->status === Job::STATUS_COMPLETED && $job->file_path && $job->file_url_hash) {
            $response['download_url'] = add_query_arg([
                'action' => 'woo_exporter_download',
                'job_id' => $job->id,
                'hash' => $job->file_url_hash
            ], admin_url('admin-ajax.php'));
        }

        // Add error message if failed
        if ($job->status === Job::STATUS_FAILED && $job->error_message) {
            $response['error_message'] = $job->error_message;
        }

        wp_send_json_success($response);
    }

    /**
     * Cancel export job
     */
    public function cancel_export_job(): void {
        // Verify nonce
        if (!check_ajax_referer('woo_exporter_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy token bezpieczeństwa.', 'woo-data-exporter')
            ], 403);
        }

        // Get job ID
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;

        if (!$job_id) {
            wp_send_json_error([
                'message' => __('Nieprawidłowe ID zadania.', 'woo-data-exporter')
            ], 400);
        }

        // Get job
        $job = Job::get($job_id);

        if (!$job) {
            wp_send_json_error([
                'message' => __('Zadanie nie zostało znalezione.', 'woo-data-exporter')
            ], 404);
        }

        // Check permissions
        if (!$this->user_can_cancel_job($job)) {
            wp_send_json_error([
                'message' => __('Nie masz uprawnień do anulowania tego zadania.', 'woo-data-exporter')
            ], 403);
        }

        // Only pending jobs can be cancelled
        if ($job->status !== Job::STATUS_PENDING) {
            wp_send_json_error([
                'message' => __('Można anulować tylko zadania oczekujące.', 'woo-data-exporter')
            ], 400);
        }

        // Update status to failed with cancellation message
        Job::update_status($job_id, Job::STATUS_FAILED, [
            'error_message' => __('Anulowane przez użytkownika', 'woo-data-exporter')
        ]);

        wp_send_json_success([
            'message' => __('Zadanie zostało anulowane.', 'woo-data-exporter')
        ]);
    }

    /**
     * Delete export job and associated file
     */
    public function delete_export_job(): void {
        // Verify nonce
        if (!check_ajax_referer('woo_exporter_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy token bezpieczeństwa.', 'woo-data-exporter')
            ], 403);
        }

        // Get job ID
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;

        if (!$job_id) {
            wp_send_json_error([
                'message' => __('Nieprawidłowe ID zadania.', 'woo-data-exporter')
            ], 400);
        }

        // Get job
        $job = Job::get($job_id);

        if (!$job) {
            wp_send_json_error([
                'message' => __('Zadanie nie zostało znalezione.', 'woo-data-exporter')
            ], 404);
        }

        // Check permissions
        if (!$this->user_can_delete_job($job)) {
            wp_send_json_error([
                'message' => __('Nie masz uprawnień do usunięcia tego zadania.', 'woo-data-exporter')
            ], 403);
        }

        // Delete file if exists
        if ($job->file_path && file_exists($job->file_path)) {
            @unlink($job->file_path);
        }

        // Delete job from database
        global $wpdb;
        $table_name = \WooExporter\Database\Schema::get_table_name();
        $deleted = $wpdb->delete($table_name, ['id' => $job_id], ['%d']);

        if ($deleted === false) {
            wp_send_json_error([
                'message' => __('Nie udało się usunąć zadania z bazy danych.', 'woo-data-exporter')
            ], 500);
        }

        wp_send_json_success([
            'message' => __('Zadanie i plik zostały usunięte.', 'woo-data-exporter')
        ]);
    }

    /**
     * Preview CSV file content
     */
    public function preview_export_csv(): void {
        // Verify nonce
        if (!check_ajax_referer('woo_exporter_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy token bezpieczeństwa.', 'woo-data-exporter')
            ], 403);
        }

        // Get job ID
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 100;

        if (!$job_id) {
            wp_send_json_error([
                'message' => __('Nieprawidłowe ID zadania.', 'woo-data-exporter')
            ], 400);
        }

        // Get job
        $job = Job::get($job_id);

        if (!$job) {
            wp_send_json_error([
                'message' => __('Zadanie nie zostało znalezione.', 'woo-data-exporter')
            ], 404);
        }

        // Check permissions
        if (!$this->user_can_view_job($job)) {
            wp_send_json_error([
                'message' => __('Nie masz uprawnień do przeglądania tego zadania.', 'woo-data-exporter')
            ], 403);
        }

        // Check if file exists
        if (!$job->file_path || !file_exists($job->file_path)) {
            wp_send_json_error([
                'message' => __('Plik nie został znaleziony.', 'woo-data-exporter')
            ], 404);
        }

        // Read CSV with pagination
        try {
            $csv_data = $this->read_csv_paginated($job->file_path, $page, $per_page);
            
            wp_send_json_success([
                'headers' => $csv_data['headers'] ?? [],
                'rows' => $csv_data['rows'] ?? [],
                'total_rows' => $csv_data['total_rows'] ?? 0,
                'current_page' => $csv_data['current_page'] ?? 1,
                'per_page' => $csv_data['per_page'] ?? 100,
                'total_pages' => $csv_data['total_pages'] ?? 1
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => __('Błąd podczas odczytu pliku CSV.', 'woo-data-exporter')
            ], 500);
        }
    }

    /**
     * Read CSV file with pagination
     *
     * @param string $file_path Path to CSV file
     * @param int $page Current page (1-based)
     * @param int $per_page Rows per page
     * @return array CSV data with pagination info
     */
    private function read_csv_paginated(string $file_path, int $page = 1, int $per_page = 100): array {
        $file = fopen($file_path, 'r');
        if (!$file) {
            throw new \Exception('Cannot open file');
        }

        // Read header
        $headers = fgetcsv($file);
        if (!$headers) {
            fclose($file);
            throw new \Exception('No headers found');
        }

        // Count total rows first (fast scan)
        $total_rows = 0;
        while (fgetcsv($file) !== false) {
            $total_rows++;
        }

        // Calculate pagination
        $total_pages = ceil($total_rows / $per_page);
        $page = max(1, min($page, $total_pages)); // Clamp page number
        $start_row = ($page - 1) * $per_page;
        $end_row = $start_row + $per_page;

        // Rewind and skip to start row
        rewind($file);
        fgetcsv($file); // Skip header again

        $current_row = 0;
        $rows = [];

        while (($row = fgetcsv($file)) !== false) {
            if ($current_row >= $start_row && $current_row < $end_row) {
                $rows[] = $row;
            }
            
            $current_row++;
            
            // Stop reading after we have what we need
            if ($current_row >= $end_row) {
                break;
            }
        }

        fclose($file);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => $total_rows,
            'current_page' => $page,
            'per_page' => $per_page,
            'total_pages' => $total_pages
        ];
    }

    /**
     * Sanitize filters array
     *
     * @param array $filters Raw filters
     * @return array Sanitized filters
     */
    private function sanitize_filters(array $filters): array {
        $sanitized = [];

        if (isset($filters['start_date'])) {
            $sanitized['start_date'] = sanitize_text_field($filters['start_date']);
        }
        if (isset($filters['end_date'])) {
            $sanitized['end_date'] = sanitize_text_field($filters['end_date']);
        }

        return $sanitized;
    }

    /**
     * Validate date format (Y-m-d)
     *
     * @param string $date Date string
     * @return bool Valid or not
     */
    private function is_valid_date(string $date): bool {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Check if user can view job
     *
     * @param object $job Job object
     * @return bool Can view or not
     */
    private function user_can_view_job(object $job): bool {
        $current_user_id = get_current_user_id();

        if (current_user_can('manage_options')) {
            return true;
        }

        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        if ($current_user_id === (int) $job->requester_id) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can cancel job
     *
     * @param object $job Job object
     * @return bool Can cancel or not
     */
    private function user_can_cancel_job(object $job): bool {
        $current_user_id = get_current_user_id();

        if (current_user_can('manage_options')) {
            return true;
        }

        if ($current_user_id === (int) $job->requester_id) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can delete job
     *
     * @param object $job Job object
     * @return bool Can delete or not
     */
    private function user_can_delete_job(object $job): bool {
        $current_user_id = get_current_user_id();

        // Only admins or job owner can delete
        if (current_user_can('manage_options')) {
            return true;
        }

        if ($current_user_id === (int) $job->requester_id) {
            return true;
        }

        return false;
    }

    /**
     * Run cron manually (admin only)
     */
    public function run_cron_manually(): void {
        // Verify nonce
        if (!check_ajax_referer('woo_exporter_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Nieprawidłowy token bezpieczeństwa.', 'woo-data-exporter')
            ], 403);
        }

        // Only admins can run cron manually
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Tylko administratorzy mogą uruchamiać cron ręcznie.', 'woo-data-exporter')
            ], 403);
        }

        // Run the cron
        error_log('WOO_EXPORTER: Manual cron trigger by user #' . get_current_user_id());
        do_action('woo_exporter_process_jobs');

        wp_send_json_success([
            'message' => __('Cron został uruchomiony. Sprawdź logi w wp-content/debug.log', 'woo-data-exporter')
        ]);
    }

    /**
     * Calculate progress percentage
     *
     * @param object $job Job object
     * @return int Progress percentage (0-100)
     */
    private function calculate_progress(object $job): int {
        if (!$job->total_items || $job->total_items == 0) {
            return 0;
        }

        $percent = round(($job->processed_items / $job->total_items) * 100);
        return min(100, max(0, $percent));
    }
}

