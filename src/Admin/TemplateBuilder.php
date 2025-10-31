<?php
/**
 * Template Builder UI
 *
 * @package WooExporter\Admin
 */

namespace WooExporter\Admin;

use WooExporter\Export\MetaScanner;
use WooExporter\Database\Template;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Builder page
 */
class TemplateBuilder {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu_page'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue assets for template builder
     */
    public function enqueue_assets($hook): void {
        if ($hook !== 'admin_page_woo-template-builder') {
            return;
        }

        // Enqueue admin CSS (reuse)
        wp_enqueue_style(
            'woo-exporter-admin',
            WOO_EXPORTER_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WOO_EXPORTER_VERSION
        );

        // Enqueue builder JS
        wp_enqueue_script(
            'woo-template-builder',
            WOO_EXPORTER_PLUGIN_URL . 'assets/js/template-builder.js',
            ['jquery'],
            WOO_EXPORTER_VERSION,
            true
        );

        // Localize
        wp_localize_script('woo-template-builder', 'wooExporterAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_exporter_nonce'),
        ]);
    }

    /**
     * Add template builder submenu
     */
    public function add_submenu_page(): void {
        add_submenu_page(
            'woo-data-exporter', // Parent menu slug
            __('Kreator Szablonu', 'woo-data-exporter'),
            __('+ Kreator Szablonu', 'woo-data-exporter'),
            'manage_woocommerce',
            'woo-template-builder',
            [$this, 'render_builder_page']
        );
    }

    /**
     * Render builder page
     */
    public function render_builder_page(): void {
        $template_id = isset($_GET['template_id']) ? absint($_GET['template_id']) : 0;
        $template = $template_id ? Template::get($template_id) : null;
        
        // Scan available fields
        $grouped_fields = MetaScanner::scan_available_fields();
        
        // Get sample orders
        $sample_orders = MetaScanner::get_sample_order_ids(5);
        $current_order_id = $sample_orders[0] ?? 0;
        
        ?>
        <div class="wrap template-builder-wrap">
            <h1><?php echo $template ? esc_html__('Edytuj Szablon', 'woo-data-exporter') : esc_html__('Nowy Szablon Eksportu', 'woo-data-exporter'); ?></h1>
            
            <form id="template-builder-form" class="template-builder-form">
                <input type="hidden" id="template_id" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                
                <div class="template-basic-info" style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Podstawowe informacje', 'woo-data-exporter'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="template_name"><?php esc_html_e('Nazwa szablonu', 'woo-data-exporter'); ?> *</label></th>
                            <td>
                                <input type="text" id="template_name" name="name" class="regular-text" required 
                                       value="<?php echo $template ? esc_attr($template->name) : ''; ?>"
                                       placeholder="np. Raport Faktur">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="template_description"><?php esc_html_e('Opis', 'woo-data-exporter'); ?></label></th>
                            <td>
                                <textarea id="template_description" name="description" class="large-text" rows="2" 
                                          placeholder="Opcjonalny opis szablonu..."><?php echo $template ? esc_textarea($template->description) : ''; ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="template-field-picker" style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Wybierz pola do eksportu', 'woo-data-exporter'); ?></h2>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <!-- Left: Available Fields -->
                        <div class="available-fields-panel">
                            <h3><?php esc_html_e('Dostępne pola', 'woo-data-exporter'); ?></h3>
                            <input type="text" id="field-search" class="regular-text" placeholder="🔍 Szukaj pola..." style="width: 100%; margin-bottom: 15px;">
                            
                            <div class="fields-list" style="max-height: 500px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                                <?php foreach ($grouped_fields as $category => $fields): ?>
                                    <div class="field-group" data-category="<?php echo esc_attr($category); ?>">
                                        <h4 style="margin: 10px 0; color: #2271b1; cursor: pointer;" class="field-group-toggle">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                            <?php echo esc_html(MetaScanner::get_category_label($category)); ?>
                                            <span style="color: #646970; font-weight: normal; font-size: 12px;">(<?php echo count($fields); ?>)</span>
                                        </h4>
                                        <div class="field-group-items" style="margin-left: 20px;">
                                            <?php foreach ($fields as $field): ?>
                                                <label class="field-item" style="display: block; padding: 5px 0; cursor: pointer;" data-field="<?php echo esc_attr($field); ?>">
                                                    <input type="checkbox" class="field-checkbox" value="<?php echo esc_attr($field); ?>" 
                                                           <?php echo ($template && in_array($field, $template->selected_fields)) ? 'checked' : ''; ?>>
                                                    <code style="font-size: 12px;"><?php echo esc_html($field); ?></code>
                                                    <span style="color: #646970; font-size: 11px;">— <?php echo esc_html(MetaScanner::get_field_label($field)); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Right: Selected Fields + Aliases -->
                        <div class="selected-fields-panel">
                            <h3><?php esc_html_e('Wybrane kolumny', 'woo-data-exporter'); ?> (<span id="selected-count">0</span>)</h3>
                            <p class="description"><?php esc_html_e('Kliknij pole aby ustawić alias (nazwę kolumny w CSV)', 'woo-data-exporter'); ?></p>
                            
                            <div id="selected-fields-list" class="selected-fields-list" style="min-height: 200px; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                                <p class="no-fields-selected" style="color: #646970; text-align: center; padding: 40px 0;">
                                    <?php esc_html_e('Zaznacz pola z lewej strony', 'woo-data-exporter'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="template-preview-panel" style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Podgląd wartości', 'woo-data-exporter'); ?></h2>
                    
                    <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                        <label><?php esc_html_e('Zamówienie ID:', 'woo-data-exporter'); ?></label>
                        <input type="number" id="preview-order-id" value="<?php echo esc_attr($current_order_id); ?>" 
                               style="width: 120px; text-align: center;" placeholder="Wpisz ID">
                        <button type="button" id="load-order-preview" class="button button-secondary">
                            <span class="dashicons dashicons-search"></span>
                            <?php esc_html_e('Załaduj Podgląd', 'woo-data-exporter'); ?>
                        </button>
                        <span id="preview-status" style="margin-left: 10px; color: #646970;"></span>
                    </div>
                    <p class="description" style="margin-top: -10px; margin-bottom: 15px;">
                        <?php esc_html_e('Przykładowe ID zamówień:', 'woo-data-exporter'); ?>
                        <?php foreach (array_slice($sample_orders, 0, 5) as $sample_id): ?>
                            <a href="#" class="quick-preview-link" data-order-id="<?php echo esc_attr($sample_id); ?>" 
                               style="margin-right: 10px;"><?php echo esc_html($sample_id); ?></a>
                        <?php endforeach; ?>
                    </p>

                    <div id="preview-table-container" style="overflow-x: auto; max-height: 400px; overflow-y: auto; border: 1px solid #ddd;">
                        <p style="text-align: center; color: #646970; padding: 40px;">
                            <?php esc_html_e('Kliknij "Załaduj" aby zobaczyć wszystkie dostępne pola z przykładowego zamówienia', 'woo-data-exporter'); ?>
                        </p>
                    </div>
                    
                    <input type="hidden" id="available-orders" value="<?php echo esc_attr(implode(',', $sample_orders)); ?>">
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-saved"></span>
                        <?php esc_html_e('Zapisz Szablon', 'woo-data-exporter'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=woo-data-exporter&tab=templates')); ?>" class="button button-large">
                        <?php esc_html_e('Anuluj', 'woo-data-exporter'); ?>
                    </a>
                </p>
            </form>
        </div>
        
        <script type="text/javascript">
            var templateBuilderData = {
                sampleOrders: [<?php echo implode(',', $sample_orders); ?>],
                currentOrderIndex: 0,
                existingTemplate: <?php echo $template ? wp_json_encode([
                    'selected_fields' => $template->selected_fields,
                    'field_aliases' => $template->field_aliases,
                    'field_order' => $template->field_order
                ]) : 'null'; ?>
            };
        </script>
        <?php
    }
}

