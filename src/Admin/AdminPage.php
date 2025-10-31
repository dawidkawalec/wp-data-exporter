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
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=schedules" 
                   class="nav-tab <?php echo $active_tab === 'schedules' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Zaplanowane Raporty', 'woo-data-exporter'); ?>
                </a>
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=templates" 
                   class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Szablony Eksportów', 'woo-data-exporter'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                if ($active_tab === 'new-export') {
                    $this->render_new_export_tab();
                } elseif ($active_tab === 'schedules') {
                    $this->render_schedules_tab();
                } elseif ($active_tab === 'templates') {
                    $this->render_templates_tab();
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
                                    <option value="custom"><?php esc_html_e('🎨 Niestandardowy (użyj szablonu)', 'woo-data-exporter'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Marketing: jeden wiersz per email. Analityka: jeden wiersz per produkt w zamówieniu.', 'woo-data-exporter'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr id="template_selector_row" style="display: none;">
                            <th scope="row">
                                <label for="template_id"><?php esc_html_e('Wybierz szablon', 'woo-data-exporter'); ?></label>
                            </th>
                            <td>
                                <select name="template_id" id="template_id" class="regular-text">
                                    <option value=""><?php esc_html_e('-- Wybierz szablon --', 'woo-data-exporter'); ?></option>
                                    <?php 
                                    $templates = \WooExporter\Database\Template::get_all();
                                    foreach ($templates as $tpl): 
                                    ?>
                                        <option value="<?php echo esc_attr($tpl->id); ?>">
                                            <?php echo esc_html($tpl->name); ?> (<?php echo count($tpl->selected_fields); ?> pól)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <a href="admin.php?page=woo-data-exporter&tab=templates"><?php esc_html_e('Zarządzaj szablonami', 'woo-data-exporter'); ?></a>
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
                        <tr>
                            <th scope="row">
                                <label for="notification_email"><?php esc_html_e('Email do powiadomienia', 'woo-data-exporter'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="notification_email" id="notification_email" class="regular-text" 
                                       placeholder="email@example.com, drugi@email.pl" />
                                <p class="description">
                                    <?php esc_html_e('Opcjonalne: adresy email do powiadomień (oddzielone przecinkami). Domyślnie: Twój email.', 'woo-data-exporter'); ?>
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;"><?php esc_html_e('Historia Eksportów', 'woo-data-exporter'); ?></h2>
                <?php if (current_user_can('manage_options')): ?>
                    <button type="button" id="run-cron-manually" class="button">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Uruchom Cron Ręcznie', 'woo-data-exporter'); ?>
                    </button>
                <?php endif; ?>
            </div>

            <!-- CSV Preview Modal -->
            <div id="csv-preview-modal" class="csv-preview-modal" style="display: none;">
                <div class="csv-preview-modal-content">
                    <div class="csv-preview-header">
                        <h3><?php esc_html_e('Podgląd CSV', 'woo-data-exporter'); ?></h3>
                        <button type="button" class="csv-preview-close">&times;</button>
                    </div>
                    <div class="csv-preview-info"></div>
                    <div class="csv-preview-body">
                        <div class="csv-preview-loading"><?php esc_html_e('Ładowanie...', 'woo-data-exporter'); ?></div>
                        <div class="csv-preview-table-wrapper"></div>
                    </div>
                </div>
            </div>

            <?php if (empty($jobs)): ?>
                <p class="no-jobs">
                    <?php esc_html_e('Brak eksportów w historii.', 'woo-data-exporter'); ?>
                </p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Źródło', 'woo-data-exporter'); ?></th>
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
                                <td><?php echo $this->render_job_source($job); ?></td>
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
     * Render job source (manual or from schedule)
     */
    private function render_job_source(object $job): string {
        if (!$job->schedule_id) {
            return '<span style="color: #646970;">' . __('Ręczny eksport', 'woo-data-exporter') . '</span>';
        }

        // Get schedule name
        $schedule = \WooExporter\Database\Schedule::get($job->schedule_id);
        
        if ($schedule) {
            $schedule_url = add_query_arg(['page' => 'woo-data-exporter', 'tab' => 'schedules'], admin_url('admin.php'));
            return sprintf(
                '<a href="%s" title="%s">🔄 %s</a>',
                esc_url($schedule_url),
                esc_attr__('Przejdź do harmonogramów', 'woo-data-exporter'),
                esc_html($schedule->name)
            );
        }

        return '<span style="color: #d63638;">' . __('Harmonogram usunięty', 'woo-data-exporter') . '</span>';
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

            // Preview button
            $actions[] = sprintf(
                '<button type="button" class="button button-small preview-export-btn" data-job-id="%d">%s</button>',
                $job->id,
                __('Podgląd', 'woo-data-exporter')
            );
        }

        if ($job->status === Job::STATUS_FAILED && $job->error_message) {
            $actions[] = sprintf(
                '<span class="error-message" title="%s">%s</span>',
                esc_attr($job->error_message),
                __('Zobacz błąd', 'woo-data-exporter')
            );
        }

        // Delete button (for all statuses)
        $actions[] = sprintf(
            '<button type="button" class="button button-small button-link-delete delete-export-btn" data-job-id="%d">%s</button>',
            $job->id,
            __('Usuń', 'woo-data-exporter')
        );

        return implode(' ', $actions);
    }

    /**
     * Render schedules tab
     */
    private function render_schedules_tab(): void {
        $schedules = \WooExporter\Database\Schedule::get_all();
        ?>
        <div class="woo-exporter-tab-schedules">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;"><?php esc_html_e('Zaplanowane Raporty', 'woo-data-exporter'); ?></h2>
                <button type="button" id="add-new-schedule" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Dodaj Nowy Harmonogram', 'woo-data-exporter'); ?>
                </button>
            </div>

            <?php if (empty($schedules)): ?>
                <div class="no-schedules" style="background: #fff; padding: 40px; text-align: center; border: 1px solid #ccd0d4;">
                    <p style="color: #646970; font-size: 14px; margin: 0;">
                        <?php esc_html_e('Brak zaplanowanych raportów. Kliknij "Dodaj Nowy Harmonogram" aby utworzyć pierwszy.', 'woo-data-exporter'); ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nazwa', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Typ', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Częstotliwość', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Email', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Następne uruchomienie', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Status', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Akcje', 'woo-data-exporter'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr data-schedule-id="<?php echo esc_attr($schedule->id); ?>">
                                <td><strong><?php echo esc_html($schedule->name); ?></strong></td>
                                <td><?php echo esc_html($this->get_job_type_label($schedule->job_type)); ?></td>
                                <td><?php echo esc_html(\WooExporter\Database\Schedule::get_frequency_description($schedule)); ?></td>
                                <td><?php echo esc_html($schedule->notification_email); ?></td>
                                <td>
                                    <?php 
                                    echo esc_html(date_i18n(
                                        get_option('date_format') . ' ' . get_option('time_format'), 
                                        strtotime($schedule->next_run_date)
                                    )); 
                                    ?>
                                </td>
                                <td>
                                    <?php if ($schedule->is_active): ?>
                                        <span class="status-badge status-completed"><?php esc_html_e('Aktywny', 'woo-data-exporter'); ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending"><?php esc_html_e('Zapauzowany', 'woo-data-exporter'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small edit-schedule-btn" data-schedule-id="<?php echo esc_attr($schedule->id); ?>">
                                        <?php esc_html_e('Edytuj', 'woo-data-exporter'); ?>
                                    </button>
                                    <button type="button" class="button button-small toggle-schedule-btn" data-schedule-id="<?php echo esc_attr($schedule->id); ?>" data-active="<?php echo esc_attr($schedule->is_active); ?>">
                                        <?php echo $schedule->is_active ? esc_html__('Pauza', 'woo-data-exporter') : esc_html__('Wznów', 'woo-data-exporter'); ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete delete-schedule-btn" data-schedule-id="<?php echo esc_attr($schedule->id); ?>">
                                        <?php esc_html_e('Usuń', 'woo-data-exporter'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Schedule Form Modal -->
            <div id="schedule-form-modal" class="csv-preview-modal" style="display: none;">
                <div class="csv-preview-modal-content" style="max-width: 700px;">
                    <div class="csv-preview-header">
                        <h3 id="schedule-modal-title"><?php esc_html_e('Nowy Harmonogram', 'woo-data-exporter'); ?></h3>
                        <button type="button" class="csv-preview-close schedule-modal-close">&times;</button>
                    </div>
                    <div class="csv-preview-body" style="max-height: none; padding: 30px;">
                        <form id="schedule-form">
                            <input type="hidden" id="schedule_id" name="schedule_id" value="">
                            
                            <table class="form-table">
                                <tr>
                                    <th><label for="schedule_name"><?php esc_html_e('Nazwa harmonogramu', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <input type="text" id="schedule_name" name="name" class="regular-text" required 
                                               placeholder="np. Raport tygodniowy" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="schedule_job_type"><?php esc_html_e('Typ eksportu', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <select id="schedule_job_type" name="job_type" class="regular-text" required>
                                            <option value="marketing_export"><?php esc_html_e('Marketing', 'woo-data-exporter'); ?></option>
                                            <option value="analytics_export"><?php esc_html_e('Analityka', 'woo-data-exporter'); ?></option>
                                            <option value="custom_export"><?php esc_html_e('🎨 Niestandardowy (szablon)', 'woo-data-exporter'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr id="schedule_template_row" style="display: none;">
                                    <th><label for="schedule_template_id"><?php esc_html_e('Szablon', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <select id="schedule_template_id" name="template_id" class="regular-text">
                                            <option value=""><?php esc_html_e('-- Wybierz szablon --', 'woo-data-exporter'); ?></option>
                                            <?php 
                                            $templates = \WooExporter\Database\Template::get_all();
                                            foreach ($templates as $tpl): 
                                            ?>
                                                <option value="<?php echo esc_attr($tpl->id); ?>">
                                                    <?php echo esc_html($tpl->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="schedule_frequency_type"><?php esc_html_e('Częstotliwość', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <select id="schedule_frequency_type" name="frequency_type" class="regular-text" required>
                                            <option value="daily"><?php esc_html_e('Codziennie / Co X dni', 'woo-data-exporter'); ?></option>
                                            <option value="weekly"><?php esc_html_e('Co tydzień (określony dzień)', 'woo-data-exporter'); ?></option>
                                            <option value="monthly"><?php esc_html_e('Co miesiąc (określony dzień)', 'woo-data-exporter'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr id="frequency_value_row">
                                    <th><label for="schedule_frequency_value" id="frequency_value_label"><?php esc_html_e('Co ile dni', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <input type="number" id="schedule_frequency_value" name="frequency_value" min="1" max="31" value="1" required />
                                        <p class="description" id="frequency_value_desc"><?php esc_html_e('1 = codziennie, 7 = co tydzień', 'woo-data-exporter'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="schedule_start_date"><?php esc_html_e('Data rozpoczęcia', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <input type="date" id="schedule_start_date" name="start_date" class="regular-text" required />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="schedule_email"><?php esc_html_e('Email powiadomienia', 'woo-data-exporter'); ?> *</label></th>
                                    <td>
                                        <input type="text" id="schedule_email" name="notification_email" class="regular-text" required 
                                               placeholder="email@example.com, drugi@email.pl" />
                                    </td>
                                </tr>
                            </table>

                            <p class="submit" style="padding: 0; margin-top: 20px;">
                                <button type="submit" class="button button-primary button-large">
                                    <?php esc_html_e('Zapisz Harmonogram', 'woo-data-exporter'); ?>
                                </button>
                                <button type="button" class="button button-large schedule-modal-close">
                                    <?php esc_html_e('Anuluj', 'woo-data-exporter'); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render templates tab
     */
    private function render_templates_tab(): void {
        $templates = \WooExporter\Database\Template::get_all();
        ?>
        <div class="woo-exporter-tab-templates">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;"><?php esc_html_e('Szablony Eksportów', 'woo-data-exporter'); ?></h2>
                <button type="button" id="add-new-template" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Utwórz Nowy Szablon', 'woo-data-exporter'); ?>
                </button>
            </div>

            <div class="templates-info-box" style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 15px; margin-bottom: 20px;">
                <p style="margin: 0;">
                    <strong>💡 Szablony niestandardowe</strong><br>
                    <?php esc_html_e('Twórz własne raporty wybierając dokładnie te pola, które Cię interesują. Możesz łączyć dane z zamówień, produktów, klientów i niestandardowych pól meta.', 'woo-data-exporter'); ?>
                </p>
            </div>

            <?php if (empty($templates)): ?>
                <div class="no-templates" style="background: #fff; padding: 40px; text-align: center; border: 1px solid #ccd0d4;">
                    <p style="color: #646970; font-size: 14px; margin: 0;">
                        <?php esc_html_e('Brak szablonów. Kliknij "Utwórz Nowy Szablon" aby stworzyć własny raport.', 'woo-data-exporter'); ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nazwa', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Opis', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Liczba pól', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Utworzony', 'woo-data-exporter'); ?></th>
                            <th><?php esc_html_e('Akcje', 'woo-data-exporter'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $template): ?>
                            <tr>
                                <td><strong><?php echo esc_html($template->name); ?></strong></td>
                                <td><?php echo esc_html($template->description ?: '—'); ?></td>
                                <td><?php echo count($template->selected_fields); ?> pól</td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($template->created_at))); ?></td>
                                <td>
                                    <button type="button" class="button button-small edit-template-btn" data-template-id="<?php echo esc_attr($template->id); ?>">
                                        <?php esc_html_e('Edytuj', 'woo-data-exporter'); ?>
                                    </button>
                                    <button type="button" class="button button-small duplicate-template-btn" data-template-id="<?php echo esc_attr($template->id); ?>">
                                        <?php esc_html_e('Duplikuj', 'woo-data-exporter'); ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete delete-template-btn" data-template-id="<?php echo esc_attr($template->id); ?>">
                                        <?php esc_html_e('Usuń', 'woo-data-exporter'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

