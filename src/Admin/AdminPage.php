<?php
/**
 * Admin page interface
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
 * Admin Page class
 */
class AdminPage {
    /**
     * Page slug
     */
    private const PAGE_SLUG = 'woo-data-exporter';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add menu page to WordPress admin
     */
    public function add_menu_page(): void {
        add_menu_page(
            __('Eksport Danych WooCommerce', 'woo-data-exporter'),
            __('Eksport Danych', 'woo-data-exporter'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-download',
            56
        );
    }

    /**
     * Enqueue CSS and JS assets
     */
    public function enqueue_assets($hook): void {
        // Only load on our plugin page
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'woo-exporter-admin',
            WOO_EXPORTER_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WOO_EXPORTER_VERSION
        );

        // Enqueue JS
        wp_enqueue_script(
            'woo-exporter-admin',
            WOO_EXPORTER_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WOO_EXPORTER_VERSION,
            true
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script('woo-exporter-admin', 'wooExporterAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_exporter_nonce'),
            'strings' => [
                'confirm_cancel' => __('Czy na pewno chcesz anulować to zadanie?', 'woo-data-exporter'),
                'processing' => __('Przetwarzanie...', 'woo-data-exporter'),
                'error' => __('Wystąpił błąd', 'woo-data-exporter'),
                'success' => __('Sukces!', 'woo-data-exporter')
            ]
        ]);
    }

    /**
     * Render admin page
     */
    public function render_page(): void {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'new-export';
        ?>
        <div class="wrap woo-exporter-admin">
            <h1><?php esc_html_e('Eksport Danych WooCommerce', 'woo-data-exporter'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=new-export" 
                   class="nav-tab <?php echo $active_tab === 'new-export' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Nowy Eksport', 'woo-data-exporter'); ?>
                </a>
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=history" 
                   class="nav-tab <?php echo $active_tab === 'history' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Historia Eksportów', 'woo-data-exporter'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                if ($active_tab === 'new-export') {
                    $this->render_new_export_tab();
                } else {
                    $this->render_history_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render new export tab
     */
    private function render_new_export_tab(): void {
        ?>
        <div class="woo-exporter-tab-new-export">
            <div class="export-form-container">
                <h2><?php esc_html_e('Utwórz Nowy Eksport', 'woo-data-exporter'); ?></h2>
                
                <form id="woo-exporter-form" class="export-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="export_type"><?php esc_html_e('Typ Eksportu', 'woo-data-exporter'); ?></label>
                            </th>
                            <td>
                                <select name="export_type" id="export_type" class="regular-text" required>
                                    <option value=""><?php esc_html_e('-- Wybierz typ --', 'woo-data-exporter'); ?></option>
                                    <option value="marketing"><?php esc_html_e('Marketing (unikalni klienci)', 'woo-data-exporter'); ?></option>
                                    <option value="analytics"><?php esc_html_e('Analityka (szczegółowe linie zamówień)', 'woo-data-exporter'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Marketing: jeden wiersz per email. Analityka: jeden wiersz per produkt w zamówieniu.', 'woo-data-exporter'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="start_date"><?php esc_html_e('Data Od', 'woo-data-exporter'); ?></label>
                            </th>
                            <td>
                                <input type="date" name="start_date" id="start_date" class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e('Opcjonalne: filtruj zamówienia od tej daty.', 'woo-data-exporter'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="end_date"><?php esc_html_e('Data Do', 'woo-data-exporter'); ?></label>
                            </th>
                            <td>
                                <input type="date" name="end_date" id="end_date" class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e('Opcjonalne: filtruj zamówienia do tej daty.', 'woo-data-exporter'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large" id="submit-export">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Generuj Eksport', 'woo-data-exporter'); ?>
                        </button>
                    </p>
                </form>

                <div id="export-result" class="export-result" style="display: none;"></div>
            </div>

            <div class="export-info-boxes">
                <div class="info-box">
                    <h3><?php esc_html_e('📊 Eksport Marketingowy', 'woo-data-exporter'); ?></h3>
                    <p><?php esc_html_e('Agregowane dane o klientach - jeden wiersz per unikalny adres email.', 'woo-data-exporter'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Email, imię, nazwisko', 'woo-data-exporter'); ?></li>
                        <li><?php esc_html_e('Zgoda marketingowa', 'woo-data-exporter'); ?></li>
                        <li><?php esc_html_e('Suma wydanych środków', 'woo-data-exporter'); ?></li>
                        <li><?php esc_html_e('Liczba zamówień', 'woo-data-exporter'); ?></li>
                    </ul>
                </div>

                <div class="info-box">
                    <h3><?php esc_html_e('📈 Eksport Analityczny', 'woo-data-exporter'); ?></h3>
                    <p><?php esc_html_e('Szczegółowe dane sprzedażowe - jeden wiersz per produkt w zamówieniu.', 'woo-data-exporter'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Pełne dane zamówienia', 'woo-data-exporter'); ?></li>
                        <li><?php esc_html_e('Dane produktów i ilości', 'woo-data-exporter'); ?></li>
                        <li><?php esc_html_e('Informacje rozliczeniowe', 'woo-data-exporter'); ?></li>
                        <li><?php esc_html_e('Użyte kupony', 'woo-data-exporter'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render history tab
     */
    private function render_history_tab(): void {
        $current_user_id = get_current_user_id();
        $jobs = current_user_can('manage_options') 
            ? $this->get_all_recent_jobs()
            : Job::get_by_requester($current_user_id, 50);
        ?>
        <div class="woo-exporter-tab-history">
            <h2><?php esc_html_e('Historia Eksportów', 'woo-data-exporter'); ?></h2>

            <?php if (empty($jobs)): ?>
                <p class="no-jobs">
                    <?php esc_html_e('Brak eksportów w historii.', 'woo-data-exporter'); ?>
                </p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Typ', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Status', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Postęp', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Data utworzenia', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Utworzone przez', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Akcje', 'woo-data-exporter'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td><?php echo esc_html($job->id); ?></td>
                                <td><?php echo esc_html($this->get_job_type_label($job->job_type)); ?></td>
                                <td><?php echo $this->render_status_badge($job->status); ?></td>
                                <td><?php echo $this->render_progress($job); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($job->created_at))); ?></td>
                                <td><?php echo esc_html($this->get_user_display_name($job->requester_id)); ?></td>
                                <td><?php echo $this->render_actions($job); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get all recent jobs (for admins)
     */
    private function get_all_recent_jobs(): array {
        global $wpdb;
        $table_name = \WooExporter\Database\Schema::get_table_name();

        $jobs = $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 100"
        );

        return $jobs ?: [];
    }

    /**
     * Get job type label
     */
    private function get_job_type_label(string $job_type): string {
        return $job_type === Job::TYPE_MARKETING 
            ? __('Marketing', 'woo-data-exporter')
            : __('Analityka', 'woo-data-exporter');
    }

    /**
     * Render status badge
     */
    private function render_status_badge(string $status): string {
        $labels = [
            Job::STATUS_PENDING => __('Oczekujące', 'woo-data-exporter'),
            Job::STATUS_PROCESSING => __('Przetwarzanie', 'woo-data-exporter'),
            Job::STATUS_COMPLETED => __('Ukończone', 'woo-data-exporter'),
            Job::STATUS_FAILED => __('Błąd', 'woo-data-exporter')
        ];

        $classes = [
            Job::STATUS_PENDING => 'status-pending',
            Job::STATUS_PROCESSING => 'status-processing',
            Job::STATUS_COMPLETED => 'status-completed',
            Job::STATUS_FAILED => 'status-failed'
        ];

        $label = $labels[$status] ?? $status;
        $class = $classes[$status] ?? '';

        return sprintf('<span class="status-badge %s">%s</span>', esc_attr($class), esc_html($label));
    }

    /**
     * Render progress
     */
    private function render_progress(object $job): string {
        if ($job->status === Job::STATUS_COMPLETED) {
            return sprintf('%d %s', $job->processed_items ?? 0, __('rekordów', 'woo-data-exporter'));
        }

        if ($job->status === Job::STATUS_PROCESSING && $job->total_items > 0) {
            $percent = round(($job->processed_items / $job->total_items) * 100);
            return sprintf('%d%% (%d/%d)', $percent, $job->processed_items, $job->total_items);
        }

        return '—';
    }

    /**
     * Get user display name
     */
    private function get_user_display_name(int $user_id): string {
        $user = get_userdata($user_id);
        return $user ? $user->display_name : __('Nieznany', 'woo-data-exporter');
    }

    /**
     * Render action buttons
     */
    private function render_actions(object $job): string {
        $actions = [];

        if ($job->status === Job::STATUS_COMPLETED && $job->file_path && $job->file_url_hash) {
            $download_url = add_query_arg([
                'action' => 'woo_exporter_download',
                'job_id' => $job->id,
                'hash' => $job->file_url_hash
            ], admin_url('admin-ajax.php'));

            $actions[] = sprintf(
                '<a href="%s" class="button button-small button-primary">%s</a>',
                esc_url($download_url),
                __('Pobierz', 'woo-data-exporter')
            );
        }

        if ($job->status === Job::STATUS_FAILED && $job->error_message) {
            $actions[] = sprintf(
                '<span class="error-message" title="%s">%s</span>',
                esc_attr($job->error_message),
                __('Zobacz błąd', 'woo-data-exporter')
            );
        }

        return implode(' ', $actions);
    }
}

