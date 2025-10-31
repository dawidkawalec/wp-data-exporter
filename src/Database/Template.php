<?php
/**
 * Template model for custom export templates
 *
 * @package WooExporter\Database
 */

namespace WooExporter\Database;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export Template model
 */
class Template {
    /**
     * Create new template
     *
     * @param array $data Template data
     * @return int|false Template ID on success, false on failure
     */
    public static function create(array $data): int|false {
        global $wpdb;
        $table_name = Schema::get_templates_table_name();

        $insert_data = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'selected_fields' => wp_json_encode($data['selected_fields']),
            'field_aliases' => isset($data['field_aliases']) ? wp_json_encode($data['field_aliases']) : null,
            'field_order' => isset($data['field_order']) ? wp_json_encode($data['field_order']) : wp_json_encode($data['selected_fields']),
            'is_global' => $data['is_global'] ?? 1,
            'created_by' => $data['created_by'] ?? get_current_user_id(),
        ];

        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d']
        );

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Get template by ID
     *
     * @param int $template_id Template ID
     * @return object|null Template object
     */
    public static function get(int $template_id): ?object {
        global $wpdb;
        $table_name = Schema::get_templates_table_name();

        $template = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $template_id)
        );

        if ($template) {
            $template->selected_fields = json_decode($template->selected_fields, true);
            $template->field_aliases = $template->field_aliases ? json_decode($template->field_aliases, true) : [];
            $template->field_order = $template->field_order ? json_decode($template->field_order, true) : $template->selected_fields;
        }

        return $template ?: null;
    }

    /**
     * Get all templates
     *
     * @param bool $global_only Only global templates
     * @return array Array of template objects
     */
    public static function get_all(bool $global_only = true): array {
        global $wpdb;
        $table_name = Schema::get_templates_table_name();

        $where = $global_only ? "WHERE is_global = 1" : "";
        
        $templates = $wpdb->get_results(
            "SELECT * FROM {$table_name} {$where} ORDER BY created_at DESC"
        );

        foreach ($templates as $template) {
            $template->selected_fields = json_decode($template->selected_fields, true);
            $template->field_aliases = $template->field_aliases ? json_decode($template->field_aliases, true) : [];
            $template->field_order = $template->field_order ? json_decode($template->field_order, true) : $template->selected_fields;
        }

        return $templates;
    }

    /**
     * Update template
     *
     * @param int $template_id Template ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public static function update(int $template_id, array $data): bool {
        global $wpdb;
        $table_name = Schema::get_templates_table_name();

        $update_data = [];
        $format = [];

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
            $format[] = '%s';
        }
        if (isset($data['description'])) {
            $update_data['description'] = $data['description'];
            $format[] = '%s';
        }
        if (isset($data['selected_fields'])) {
            $update_data['selected_fields'] = wp_json_encode($data['selected_fields']);
            $format[] = '%s';
        }
        if (isset($data['field_aliases'])) {
            $update_data['field_aliases'] = wp_json_encode($data['field_aliases']);
            $format[] = '%s';
        }
        if (isset($data['field_order'])) {
            $update_data['field_order'] = wp_json_encode($data['field_order']);
            $format[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $template_id],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete template
     *
     * @param int $template_id Template ID
     * @return bool Success status
     */
    public static function delete(int $template_id): bool {
        global $wpdb;
        $table_name = Schema::get_templates_table_name();

        $result = $wpdb->delete(
            $table_name,
            ['id' => $template_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Duplicate template
     *
     * @param int $template_id Template ID to duplicate
     * @return int|false New template ID on success
     */
    public static function duplicate(int $template_id): int|false {
        $original = self::get($template_id);
        
        if (!$original) {
            return false;
        }

        $data = [
            'name' => $original->name . ' (kopia)',
            'description' => $original->description,
            'selected_fields' => $original->selected_fields,
            'field_aliases' => $original->field_aliases,
            'field_order' => $original->field_order,
            'is_global' => $original->is_global,
            'created_by' => get_current_user_id(),
        ];

        return self::create($data);
    }
}

