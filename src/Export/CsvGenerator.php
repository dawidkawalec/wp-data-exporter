<?php
/**
 * CSV file generator using league/csv
 *
 * @package WooExporter\Export
 */

namespace WooExporter\Export;

use League\Csv\Writer;
use League\Csv\Exception as CsvException;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV Generator class
 */
class CsvGenerator {
    /**
     * Marketing export headers
     */
    private const MARKETING_HEADERS = [
        'email',
        'first_name',
        'last_name',
        'zgoda_marketingowa',
        'total_spent',
        'order_count',
        'last_order_date'
    ];

    /**
     * Analytics export headers
     */
    private const ANALYTICS_HEADERS = [
        'order_id',
        'order_date',
        'order_status',
        'order_total',
        'order_currency',
        'billing_email',
        'billing_phone',
        'billing_full_name',
        'billing_city',
        'billing_postcode',
        'user_id',
        'item_name',
        'item_quantity',
        'item_total',
        'coupons_used',
        'zgoda_marketingowa'
    ];

    /**
     * @var Writer CSV writer instance
     */
    private Writer $writer;

    /**
     * @var string File path
     */
    private string $file_path;

    /**
     * @var string Export type
     */
    private string $export_type;

    /**
     * @var object|null Template object for custom exports
     */
    private ?object $template;

    /**
     * Constructor
     *
     * @param string $export_type Export type (marketing_export, analytics_export, custom_export)
     * @param object|null $template Template object (required for custom_export)
     * @throws CsvException
     */
    public function __construct(string $export_type, ?object $template = null) {
        $this->export_type = $export_type;
        $this->template = $template;
        $this->file_path = $this->generate_file_path();
        
        // Ensure directory exists
        $dir = dirname($this->file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Initialize CSV writer
        $this->writer = Writer::createFromPath($this->file_path, 'w+');
        $this->writer->setDelimiter(',');
        $this->writer->setEnclosure('"');
        
        // Add BOM for Excel UTF-8 compatibility
        $this->writer->setOutputBOM(Writer::BOM_UTF8);

        // Insert headers
        $headers = $this->get_headers();
        $this->writer->insertOne($headers);
    }

    /**
     * Generate unique file path
     *
     * @return string File path
     */
    private function generate_file_path(): string {
        $upload_dir = WOO_EXPORTER_UPLOADS_DIR;
        $timestamp = current_time('Y-m-d_H-i-s');
        $random = wp_generate_password(8, false);
        $filename = "{$this->export_type}_{$timestamp}_{$random}.csv";
        
        return $upload_dir . $filename;
    }

    /**
     * Get headers based on export type
     *
     * @return array Headers
     */
    private function get_headers(): array {
        if ($this->export_type === 'custom_export' && $this->template) {
            // Use template field order (or selected_fields if no order)
            $fields = $this->template->field_order ?: $this->template->selected_fields;
            
            // Apply aliases
            $headers = [];
            foreach ($fields as $field) {
                $headers[] = $this->template->field_aliases[$field] ?? $field;
            }
            
            return $headers;
        }
        
        return $this->export_type === 'marketing_export' 
            ? self::MARKETING_HEADERS 
            : self::ANALYTICS_HEADERS;
    }

    /**
     * Write batch of rows to CSV
     *
     * @param array $rows Array of data rows
     * @return bool Success status
     */
    public function write_batch(array $rows): bool {
        if (empty($rows)) {
            return true;
        }

        try {
            // Prepare rows according to headers
            $prepared_rows = [];
            
            // For custom exports, use template field order
            if ($this->export_type === 'custom_export' && $this->template) {
                $field_order = $this->template->field_order ?: $this->template->selected_fields;
                
                foreach ($rows as $row) {
                    $prepared_row = [];
                    foreach ($field_order as $field) {
                        $prepared_row[] = $row[$field] ?? '';
                    }
                    $prepared_rows[] = $prepared_row;
                }
            } else {
                // Standard exports
                $headers = $this->get_headers();
                foreach ($rows as $row) {
                    $prepared_row = [];
                    foreach ($headers as $header) {
                        $prepared_row[] = $row[$header] ?? '';
                    }
                    $prepared_rows[] = $prepared_row;
                }
            }

            $this->writer->insertAll($prepared_rows);
            return true;
        } catch (CsvException $e) {
            error_log('CSV write error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file path
     *
     * @return string File path
     */
    public function get_file_path(): string {
        return $this->file_path;
    }

    /**
     * Get file size in bytes
     *
     * @return int File size
     */
    public function get_file_size(): int {
        return file_exists($this->file_path) ? filesize($this->file_path) : 0;
    }

    /**
     * Close the CSV writer (flush data)
     *
     * @return void
     */
    public function close(): void {
        // League CSV automatically flushes on destruction
        // But we can explicitly unset the writer
        unset($this->writer);
    }

    /**
     * Delete the generated file
     *
     * @return bool Success status
     */
    public function delete_file(): bool {
        if (file_exists($this->file_path)) {
            return unlink($this->file_path);
        }
        return false;
    }

    /**
     * Format order status for display
     *
     * @param string $status Raw status
     * @return string Formatted status
     */
    public static function format_order_status(string $status): string {
        return str_replace('wc-', '', $status);
    }

    /**
     * Clean and sanitize data for CSV export
     *
     * @param array $data Raw data array
     * @return array Cleaned data
     */
    public static function sanitize_export_data(array $data): array {
        foreach ($data as &$row) {
            foreach ($row as $key => &$value) {
                // Convert null to empty string
                if ($value === null) {
                    $value = '';
                }
                
                // Clean order status
                if ($key === 'order_status' && is_string($value)) {
                    $value = self::format_order_status($value);
                }
                
                // Format dates
                if (in_array($key, ['order_date', 'last_order_date']) && !empty($value)) {
                    $value = date('Y-m-d H:i:s', strtotime($value));
                }
                
                // Format numbers
                if (in_array($key, ['total_spent', 'order_total', 'item_total']) && !empty($value)) {
                    $value = number_format((float)$value, 2, '.', '');
                }
                
                // Clean strings
                if (is_string($value)) {
                    $value = trim($value);
                }
            }
        }
        
        return $data;
    }
}

