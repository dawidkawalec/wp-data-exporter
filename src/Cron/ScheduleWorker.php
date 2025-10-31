<?php
/**
 * Cron worker for processing scheduled exports
 *
 * @package WooExporter\Cron
 */

namespace WooExporter\Cron;

use WooExporter\Database\Schedule;
use WooExporter\Database\Job;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schedule Worker - checks and creates jobs from schedules
 */
class ScheduleWorker {
    /**
     * Constructor - register cron hook
     */
    public function __construct() {
        add_action('woo_exporter_check_schedules', [$this, 'check_and_run_schedules']);
    }

    /**
     * Check schedules and create jobs for due ones
     */
    public function check_and_run_schedules(): void {
        error_log('WOO_EXPORTER_SCHEDULE: Checking schedules at ' . current_time('mysql'));

        // Get schedules that are due
        $due_schedules = Schedule::get_due_schedules();

        if (empty($due_schedules)) {
            error_log('WOO_EXPORTER_SCHEDULE: No due schedules found');
            return;
        }

        error_log('WOO_EXPORTER_SCHEDULE: Found ' . count($due_schedules) . ' due schedule(s)');

        foreach ($due_schedules as $schedule) {
            $this->process_schedule($schedule);
        }
    }

    /**
     * Process a single schedule
     *
     * @param object $schedule Schedule object
     */
    private function process_schedule(object $schedule): void {
        error_log('WOO_EXPORTER_SCHEDULE: Processing schedule #' . $schedule->id . ' (' . $schedule->name . ')');

        try {
            // Calculate date range filters for the period
            $filters = $this->calculate_period_filters($schedule);

            // Merge with existing filters from schedule
            if (!empty($schedule->filters)) {
                $filters = array_merge($schedule->filters, $filters);
            }
            
            // Add template_id for custom exports
            if ($schedule->job_type === 'custom_export' && !empty($schedule->template_id)) {
                $filters['template_id'] = $schedule->template_id;
            }

            // Create export job
            $job_id = Job::create(
                $schedule->job_type,
                $filters,
                $schedule->created_by,
                $schedule->notification_email,
                $schedule->id
            );

            if (!$job_id) {
                throw new \Exception('Failed to create job from schedule');
            }

            error_log('WOO_EXPORTER_SCHEDULE: Created job #' . $job_id . ' from schedule #' . $schedule->id);

            // Mark schedule as run (updates next_run_date)
            Schedule::mark_as_run($schedule->id);

            error_log('WOO_EXPORTER_SCHEDULE: Schedule #' . $schedule->id . ' marked as run. Next run: ' . Schedule::get($schedule->id)->next_run_date);

        } catch (\Exception $e) {
            error_log('WOO_EXPORTER_SCHEDULE ERROR: Failed to process schedule #' . $schedule->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Calculate date filters for the reporting period
     *
     * @param object $schedule Schedule object
     * @return array Date filters
     */
    private function calculate_period_filters(object $schedule): array {
        $now = new \DateTime(current_time('Y-m-d'));
        $filters = [];

        switch ($schedule->frequency_type) {
            case Schedule::FREQ_DAILY:
                // For daily: previous X days
                $days = $schedule->frequency_value;
                $start = clone $now;
                $start->modify("-{$days} days");
                $filters['start_date'] = $start->format('Y-m-d');
                $filters['end_date'] = $now->modify('-1 day')->format('Y-m-d');
                break;

            case Schedule::FREQ_WEEKLY:
                // For weekly: last 7 days
                $start = clone $now;
                $start->modify('-7 days');
                $filters['start_date'] = $start->format('Y-m-d');
                $filters['end_date'] = $now->modify('-1 day')->format('Y-m-d');
                break;

            case Schedule::FREQ_MONTHLY:
                // For monthly: last month
                $start = clone $now;
                $start->modify('first day of last month');
                $end = clone $now;
                $end->modify('last day of last month');
                $filters['start_date'] = $start->format('Y-m-d');
                $filters['end_date'] = $end->format('Y-m-d');
                break;
        }

        error_log('WOO_EXPORTER_SCHEDULE: Period filters for schedule #' . $schedule->id . ': ' . json_encode($filters));

        return $filters;
    }
}

