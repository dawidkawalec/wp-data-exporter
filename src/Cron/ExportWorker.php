<?php
/**
 * Cron worker for processing export jobs in background
 *
 * @package WooExporter\Cron
 */

namespace WooExporter\Cron;

use WooExporter\Database\Job;
use WooExporter\Export\DataQuery;
use WooExporter\Export\CsvGenerator;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export Worker class - processes jobs in background
 */
class ExportWorker {
    /**
     * Batch size for processing (number of records per iteration)
     */
    private const BATCH_SIZE = 500;

    /**
     * Maximum execution time per cron run (seconds)
     */
    private const MAX_EXECUTION_TIME = 45;

    /**
     * Constructor - register cron hook
     */
    public function __construct() {
        add_action('woo_exporter_process_jobs', [$this, 'process_pending_jobs']);
    }

    /**
     * Process pending jobs (called by WP Cron)
     */
    public function process_pending_jobs(): void {
        error_log('WOO_EXPORTER: Cron worker started at ' . current_time('mysql'));
        
        $start_time = time();

        // Get pending jobs (limit to prevent overload)
        $pending_jobs = Job::get_by_status(Job::STATUS_PENDING, 5);

        if (empty($pending_jobs)) {
            error_log('WOO_EXPORTER: No pending jobs found');
            return;
        }

        error_log('WOO_EXPORTER: Found ' . count($pending_jobs) . ' pending job(s)');

        foreach ($pending_jobs as $job) {
            error_log('WOO_EXPORTER: Processing job #' . $job->id . ' (' . $job->job_type . ')');
            
            // Check execution time limit
            if ((time() - $start_time) > self::MAX_EXECUTION_TIME) {
                error_log('WOO_EXPORTER: Max execution time reached, stopping');
                break;
            }

            $this->process_job($job);
        }
        
        error_log('WOO_EXPORTER: Cron worker finished');
    }

    /**
     * Process a single job
     *
     * @param object $job Job object
     */
    private function process_job(object $job): void {
        error_log('WOO_EXPORTER: Job #' . $job->id . ' - Updating status to processing');
        
        // Update status to processing
        Job::update_status($job->id, Job::STATUS_PROCESSING);

        try {
            // Get total count
            error_log('WOO_EXPORTER: Job #' . $job->id . ' - Getting total count');
            $total_count = $this->get_total_count($job->job_type, $job->filters ?? []);
            error_log('WOO_EXPORTER: Job #' . $job->id . ' - Total items: ' . $total_count);
            Job::update_progress($job->id, 0, $total_count);

            // Initialize CSV generator
            error_log('WOO_EXPORTER: Job #' . $job->id . ' - Initializing CSV generator');
            
            // Get template for custom exports
            $template = null;
            if ($job->job_type === Job::TYPE_CUSTOM && !empty($job->filters['template_id'])) {
                $template = \WooExporter\Database\Template::get($job->filters['template_id']);
            }
            
            $csv_generator = new CsvGenerator($job->job_type, $template);
            
            $offset = 0;
            $processed = 0;

            // Process in batches
            error_log('WOO_EXPORTER: Job #' . $job->id . ' - Starting batch processing');
            while ($offset < $total_count) {
                error_log('WOO_EXPORTER: Job #' . $job->id . ' - Fetching batch at offset ' . $offset);
                $batch_data = $this->get_batch_data($job->job_type, $job->filters ?? [], $offset, self::BATCH_SIZE);
                
                if (empty($batch_data)) {
                    error_log('WOO_EXPORTER: Job #' . $job->id . ' - Empty batch, stopping');
                    break;
                }

                error_log('WOO_EXPORTER: Job #' . $job->id . ' - Processing ' . count($batch_data) . ' items');
                
                // Sanitize and write batch
                $sanitized_data = CsvGenerator::sanitize_export_data($batch_data);
                $success = $csv_generator->write_batch($sanitized_data);

                if (!$success) {
                    throw new \Exception('Failed to write batch to CSV');
                }

                $processed += count($batch_data);
                $offset += self::BATCH_SIZE;

                // Update progress
                Job::update_progress($job->id, $processed);

                // Free memory
                unset($batch_data, $sanitized_data);
            }

            // Close CSV file
            error_log('WOO_EXPORTER: Job #' . $job->id . ' - Closing CSV file');
            $csv_generator->close();

            // Update job as completed
            error_log('WOO_EXPORTER: Job #' . $job->id . ' - Marking as completed. Processed: ' . $processed . ' items');
            Job::update_status($job->id, Job::STATUS_COMPLETED, [
                'file_path' => $csv_generator->get_file_path(),
                'processed_items' => $processed
            ]);

            // Send email notification (optional - may fail if emails are disabled)
            try {
                $this->send_completion_email($job, $csv_generator->get_file_path());
            } catch (\Exception $email_error) {
                error_log('Export job #' . $job->id . ' completed but email failed: ' . $email_error->getMessage());
            }

        } catch (\Exception $e) {
            // Mark job as failed
            Job::update_status($job->id, Job::STATUS_FAILED, [
                'error_message' => $e->getMessage()
            ]);

            // Log error
            error_log('WOO_EXPORTER ERROR - Job #' . $job->id . ' failed: ' . $e->getMessage());
            error_log('WOO_EXPORTER ERROR - Stack trace: ' . $e->getTraceAsString());

            // Try to send failure email (optional)
            try {
                $this->send_failure_email($job, $e->getMessage());
            } catch (\Exception $email_error) {
                error_log('WOO_EXPORTER ERROR - Failed to send failure email: ' . $email_error->getMessage());
            }
        }
    }

    /**
     * Get total count based on job type
     *
     * @param string $job_type Job type
     * @param array $filters Filters
     * @return int Total count
     */
    private function get_total_count(string $job_type, array $filters): int {
        if ($job_type === Job::TYPE_MARKETING) {
            return DataQuery::get_marketing_count($filters);
        } elseif ($job_type === Job::TYPE_ANALYTICS) {
            return DataQuery::get_analytics_count($filters);
        } elseif ($job_type === Job::TYPE_CUSTOM && !empty($filters['template_id'])) {
            return DataQuery::get_custom_count($filters['template_id'], $filters);
        }
        return 0;
    }

    /**
     * Get batch data based on job type
     *
     * @param string $job_type Job type
     * @param array $filters Filters
     * @param int $offset Offset
     * @param int $limit Limit
     * @return array Batch data
     */
    private function get_batch_data(string $job_type, array $filters, int $offset, int $limit): array {
        if ($job_type === Job::TYPE_MARKETING) {
            return DataQuery::get_marketing_data($filters, $offset, $limit);
        } elseif ($job_type === Job::TYPE_ANALYTICS) {
            return DataQuery::get_analytics_data($filters, $offset, $limit);
        } elseif ($job_type === Job::TYPE_CUSTOM && !empty($filters['template_id'])) {
            return DataQuery::get_custom_data($filters['template_id'], $filters, $offset, $limit);
        }
        return [];
    }

    /**
     * Send completion email to requester
     *
     * @param object $job Job object
     * @param string $file_path Path to generated file
     */
    private function send_completion_email(object $job, string $file_path): void {
        // Determine recipient(s)
        $recipients = [];
        
        if (!empty($job->notification_email)) {
            // Use custom notification email(s)
            $recipients = array_map('trim', explode(',', $job->notification_email));
        } else {
            // Fallback to requester email
            $user = get_userdata($job->requester_id);
            if ($user) {
                $recipients[] = $user->user_email;
            }
        }

        if (empty($recipients)) {
            return;
        }

        $job_obj = Job::get($job->id);
        $download_url = $this->get_download_url($job_obj);

        $subject = sprintf(
            __('[%s] Eksport danych gotowy', 'woo-data-exporter'),
            get_bloginfo('name')
        );

        $job_type_label = $job->job_type === Job::TYPE_MARKETING 
            ? __('Marketing', 'woo-data-exporter')
            : __('Analityka', 'woo-data-exporter');

        $message = sprintf(
            __("Twój eksport danych został pomyślnie wygenerowany.\n\nTyp eksportu: %s\nLiczba rekordów: %d\nData utworzenia: %s\n\nPobierz plik:\n%s\n\nLink będzie aktywny przez 7 dni.\n\nPozdrawiam,\n%s", 'woo-data-exporter'),
            $job_type_label,
            $job->processed_items ?? 0,
            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($job->created_at)),
            $download_url,
            get_bloginfo('name')
        );

        // Send to all recipients
        foreach ($recipients as $recipient) {
            wp_mail($recipient, $subject, $message);
        }
    }

    /**
     * Send failure email to requester
     *
     * @param object $job Job object
     * @param string $error_message Error message
     */
    private function send_failure_email(object $job, string $error_message): void {
        $user = get_userdata($job->requester_id);
        if (!$user) {
            return;
        }

        $subject = sprintf(
            __('[%s] Błąd eksportu danych', 'woo-data-exporter'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Witaj %s,\n\nNiestety wystąpił błąd podczas generowania Twojego eksportu.\n\nBłąd: %s\n\nSkontaktuj się z administratorem strony.\n\nPozdrawiam,\n%s", 'woo-data-exporter'),
            $user->display_name,
            $error_message,
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Get download URL for job
     *
     * @param object $job Job object
     * @return string Download URL
     */
    private function get_download_url(object $job): string {
        return add_query_arg([
            'action' => 'woo_exporter_download',
            'job_id' => $job->id,
            'hash' => $job->file_url_hash
        ], admin_url('admin-ajax.php'));
    }
}

