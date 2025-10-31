<?php
/**
 * Job model for managing export jobs
 *
 * @package WooExporter\Database
 */

namespace WooExporter\Database;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export Job model
 */
class Job {
    /**
     * Job statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Job types
     */
    public const TYPE_MARKETING = 'marketing_export';
    public const TYPE_ANALYTICS = 'analytics_export';

    /**
     * Create a new job
     *
     * @param string $job_type Job type (marketing_export or analytics_export)
     * @param array $filters Optional filters (start_date, end_date, etc.)
     * @param int $requester_id User ID who requested the export
     * @param string|null $notification_email Email(s) for notifications (comma-separated)
     * @param int|null $schedule_id Schedule ID if auto-generated
     * @return int|false Job ID on success, false on failure
     */
    public static function create(
        string $job_type, 
        array $filters = [], 
        int $requester_id = 0,
        ?string $notification_email = null,
        ?int $schedule_id = null
    ): int|false {
        global $wpdb;

        if ($requester_id === 0) {
            $requester_id = get_current_user_id();
        }

        $table_name = Schema::get_table_name();

        $data = [
            'job_type' => $job_type,
            'status' => self::STATUS_PENDING,
            'filters' => !empty($filters) ? wp_json_encode($filters) : null,
            'requester_id' => $requester_id,
            'file_url_hash' => wp_generate_password(32, false),
        ];

        $format = ['%s', '%s', '%s', '%d', '%s'];

        if ($notification_email !== null) {
            $data['notification_email'] = $notification_email;
            $format[] = '%s';
        }

        if ($schedule_id !== null) {
            $data['schedule_id'] = $schedule_id;
            $format[] = '%d';
        }

        $result = $wpdb->insert($table_name, $data, $format);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Get job by ID
     *
     * @param int $job_id Job ID
     * @return object|null Job object or null if not found
     */
    public static function get(int $job_id): ?object {
        global $wpdb;
        $table_name = Schema::get_table_name();

        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $job_id)
        );

        if ($job && $job->filters) {
            $job->filters = json_decode($job->filters, true);
        }

        return $job ?: null;
    }

    /**
     * Get jobs by status
     *
     * @param string $status Job status
     * @param int $limit Maximum number of jobs to retrieve
     * @return array Array of job objects
     */
    public static function get_by_status(string $status, int $limit = 10): array {
        global $wpdb;
        $table_name = Schema::get_table_name();

        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
                $status,
                $limit
            )
        );

        foreach ($jobs as $job) {
            if ($job->filters) {
                $job->filters = json_decode($job->filters, true);
            }
        }

        return $jobs;
    }

    /**
     * Get jobs by requester
     *
     * @param int $requester_id User ID
     * @param int $limit Maximum number of jobs
     * @return array Array of job objects
     */
    public static function get_by_requester(int $requester_id, int $limit = 50): array {
        global $wpdb;
        $table_name = Schema::get_table_name();

        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE requester_id = %d ORDER BY created_at DESC LIMIT %d",
                $requester_id,
                $limit
            )
        );

        foreach ($jobs as $job) {
            if ($job->filters) {
                $job->filters = json_decode($job->filters, true);
            }
        }

        return $jobs;
    }

    /**
     * Update job status
     *
     * @param int $job_id Job ID
     * @param string $status New status
     * @param array $data Additional data to update
     * @return bool Success status
     */
    public static function update_status(int $job_id, string $status, array $data = []): bool {
        global $wpdb;
        $table_name = Schema::get_table_name();

        $update_data = array_merge(['status' => $status], $data);
        
        if ($status === self::STATUS_COMPLETED && !isset($update_data['completed_at'])) {
            $update_data['completed_at'] = current_time('mysql');
        }

        $format = [];
        foreach ($update_data as $key => $value) {
            $format[] = is_int($value) ? '%d' : '%s';
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $job_id],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Update job progress
     *
     * @param int $job_id Job ID
     * @param int $processed Number of processed items
     * @param int|null $total Total number of items (optional)
     * @return bool Success status
     */
    public static function update_progress(int $job_id, int $processed, ?int $total = null): bool {
        global $wpdb;
        $table_name = Schema::get_table_name();

        $data = ['processed_items' => $processed];
        $format = ['%d'];

        if ($total !== null) {
            $data['total_items'] = $total;
            $format[] = '%d';
        }

        $result = $wpdb->update(
            $table_name,
            $data,
            ['id' => $job_id],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete old completed jobs
     *
     * @param int $days_old Number of days to keep
     * @return int Number of deleted jobs
     */
    public static function delete_old_jobs(int $days_old = 30): int {
        global $wpdb;
        $table_name = Schema::get_table_name();

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} 
                WHERE status = %s 
                AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                self::STATUS_COMPLETED,
                $days_old
            )
        );

        return $deleted ?: 0;
    }
}

