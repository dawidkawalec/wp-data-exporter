<?php
/**
 * Schedule model for managing export schedules
 *
 * @package WooExporter\Database
 */

namespace WooExporter\Database;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export Schedule model
 */
class Schedule {
    /**
     * Frequency types
     */
    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';

    /**
     * Create a new schedule
     *
     * @param array $data Schedule data
     * @return int|false Schedule ID on success, false on failure
     */
    public static function create(array $data): int|false {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();

        // Calculate first next_run_date
        $next_run = self::calculate_next_run(
            $data['start_date'],
            $data['frequency_type'],
            $data['frequency_value']
        );

        $insert_data = [
            'name' => $data['name'],
            'job_type' => $data['job_type'],
            'frequency_type' => $data['frequency_type'],
            'frequency_value' => $data['frequency_value'],
            'start_date' => $data['start_date'],
            'next_run_date' => $next_run,
            'notification_email' => $data['notification_email'],
            'filters' => isset($data['filters']) && !empty($data['filters']) ? wp_json_encode($data['filters']) : null,
            'is_active' => $data['is_active'] ?? 1,
            'created_by' => $data['created_by'] ?? get_current_user_id(),
        ];
        
        $format = ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d'];
        
        // Add template_id only if present (for custom exports)
        if (isset($data['template_id']) && $data['template_id']) {
            $insert_data['template_id'] = $data['template_id'];
            // Insert after job_type
            $format = ['%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d'];
        }
        
        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            $format
        );

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Get schedule by ID
     *
     * @param int $schedule_id Schedule ID
     * @return object|null Schedule object or null
     */
    public static function get(int $schedule_id): ?object {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();

        $schedule = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $schedule_id)
        );

        if ($schedule && $schedule->filters) {
            $schedule->filters = json_decode($schedule->filters, true);
        }

        return $schedule ?: null;
    }

    /**
     * Get all schedules
     *
     * @param bool $active_only Only active schedules
     * @return array Array of schedule objects
     */
    public static function get_all(bool $active_only = false): array {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();

        $where = $active_only ? "WHERE is_active = 1" : "";
        
        $schedules = $wpdb->get_results(
            "SELECT * FROM {$table_name} {$where} ORDER BY created_at DESC"
        );

        foreach ($schedules as $schedule) {
            if ($schedule->filters) {
                $schedule->filters = json_decode($schedule->filters, true);
            }
        }

        return $schedules;
    }

    /**
     * Get schedules that need to run
     *
     * @return array Array of schedule objects
     */
    public static function get_due_schedules(): array {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();
        $now = current_time('mysql');

        $schedules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE is_active = 1 
            AND next_run_date <= %s 
            ORDER BY next_run_date ASC",
            $now
        ));

        foreach ($schedules as $schedule) {
            if ($schedule->filters) {
                $schedule->filters = json_decode($schedule->filters, true);
            }
        }

        return $schedules;
    }

    /**
     * Update schedule
     *
     * @param int $schedule_id Schedule ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public static function update(int $schedule_id, array $data): bool {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();

        $update_data = [];
        $format = [];

        $allowed_fields = ['name', 'job_type', 'template_id', 'frequency_type', 'frequency_value', 
                          'start_date', 'notification_email', 'filters', 'is_active'];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'filters') {
                    $update_data[$field] = !empty($data[$field]) ? wp_json_encode($data[$field]) : null;
                } else {
                    $update_data[$field] = $data[$field];
                }
                $format[] = in_array($field, ['frequency_value', 'is_active']) ? '%d' : '%s';
            }
        }

        // Recalculate next_run if frequency changed
        if (isset($data['frequency_type']) || isset($data['frequency_value']) || isset($data['start_date'])) {
            $schedule = self::get($schedule_id);
            $next_run = self::calculate_next_run(
                $data['start_date'] ?? $schedule->start_date,
                $data['frequency_type'] ?? $schedule->frequency_type,
                $data['frequency_value'] ?? $schedule->frequency_value
            );
            $update_data['next_run_date'] = $next_run;
            $format[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $schedule_id],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Mark schedule as run and calculate next run
     *
     * @param int $schedule_id Schedule ID
     * @return bool Success status
     */
    public static function mark_as_run(int $schedule_id): bool {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();

        $schedule = self::get($schedule_id);
        if (!$schedule) {
            return false;
        }

        $next_run = self::calculate_next_run_from_now(
            $schedule->frequency_type,
            $schedule->frequency_value
        );

        $result = $wpdb->update(
            $table_name,
            [
                'last_run_at' => current_time('mysql'),
                'next_run_date' => $next_run
            ],
            ['id' => $schedule_id],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Toggle active status
     *
     * @param int $schedule_id Schedule ID
     * @param bool $is_active Active status
     * @return bool Success status
     */
    public static function toggle_active(int $schedule_id, bool $is_active): bool {
        return self::update($schedule_id, ['is_active' => $is_active ? 1 : 0]);
    }

    /**
     * Delete schedule
     *
     * @param int $schedule_id Schedule ID
     * @return bool Success status
     */
    public static function delete(int $schedule_id): bool {
        global $wpdb;
        $table_name = Schema::get_schedules_table_name();

        $result = $wpdb->delete(
            $table_name,
            ['id' => $schedule_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Calculate next run date from start date
     *
     * @param string $start_date Start date (Y-m-d or Y-m-d H:i:s)
     * @param string $frequency_type Frequency type
     * @param int $frequency_value Frequency value
     * @return string Next run datetime (Y-m-d H:i:s)
     */
    private static function calculate_next_run(string $start_date, string $frequency_type, int $frequency_value): string {
        // Support datetime or just date
        $start = new \DateTime($start_date);
        $now = new \DateTime(current_time('Y-m-d H:i:s'));

        // If start date is in the future, use it
        if ($start > $now) {
            return $start->format('Y-m-d H:i:s');
        }

        // Otherwise calculate next occurrence from now
        return self::calculate_next_run_from_now($frequency_type, $frequency_value);
    }

    /**
     * Calculate next run from current time
     *
     * @param string $frequency_type Frequency type
     * @param int $frequency_value Frequency value
     * @return string Next run datetime
     */
    private static function calculate_next_run_from_now(string $frequency_type, int $frequency_value): string {
        $next = new \DateTime(current_time('Y-m-d H:i:s'));

        switch ($frequency_type) {
            case self::FREQ_DAILY:
                // Run every X days
                $next->modify("+{$frequency_value} days");
                break;

            case self::FREQ_WEEKLY:
                // Run on specific day of week (1=Monday, 7=Sunday)
                $target_day = $frequency_value;
                $current_day = (int) $next->format('N');
                
                $days_diff = $target_day - $current_day;
                if ($days_diff <= 0) {
                    $days_diff += 7;
                }
                
                $next->modify("+{$days_diff} days");
                break;

            case self::FREQ_MONTHLY:
                // Run on specific day of month (1-31)
                $target_day = min($frequency_value, 31);
                $next->modify('first day of next month');
                $next->setDate(
                    (int) $next->format('Y'),
                    (int) $next->format('m'),
                    min($target_day, (int) $next->format('t'))
                );
                break;
        }

        return $next->format('Y-m-d 00:00:00');
    }

    /**
     * Get human-readable frequency description
     *
     * @param object $schedule Schedule object
     * @return string Description
     */
    public static function get_frequency_description(object $schedule): string {
        switch ($schedule->frequency_type) {
            case self::FREQ_DAILY:
                if ($schedule->frequency_value == 1) {
                    return __('Codziennie', 'woo-data-exporter');
                }
                return sprintf(__('Co %d dni', 'woo-data-exporter'), $schedule->frequency_value);

            case self::FREQ_WEEKLY:
                $days = [
                    1 => __('Poniedziałek', 'woo-data-exporter'),
                    2 => __('Wtorek', 'woo-data-exporter'),
                    3 => __('Środa', 'woo-data-exporter'),
                    4 => __('Czwartek', 'woo-data-exporter'),
                    5 => __('Piątek', 'woo-data-exporter'),
                    6 => __('Sobota', 'woo-data-exporter'),
                    7 => __('Niedziela', 'woo-data-exporter'),
                ];
                return sprintf(__('Co tydzień w %s', 'woo-data-exporter'), $days[$schedule->frequency_value] ?? '?');

            case self::FREQ_MONTHLY:
                return sprintf(__('Co miesiąc %d. dnia', 'woo-data-exporter'), $schedule->frequency_value);

            default:
                return __('Nieznany', 'woo-data-exporter');
        }
    }
}

