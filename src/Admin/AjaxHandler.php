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

        // Create job
        $job_id = Job::create($job_type, $filters, get_current_user_id());

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

