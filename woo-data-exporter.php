<?php
/**
 * Plugin Name: WooCommerce Advanced Data Exporter
 * Plugin URI: https://github.com/dawidkawalec/wp-data-exporter
 * Description: Zaawansowany eksporter danych WooCommerce z przetwarzaniem w tle. Umożliwia generowanie raportów marketingowych i analitycznych bez ryzyka timeout'ów.
 * Version: 1.0.0
 * Author: Dawid Kawalec
 * Author URI: https://github.com/dawidkawalec
 * Text Domain: woo-data-exporter
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace WooExporter;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WOO_EXPORTER_VERSION', '1.0.0');
define('WOO_EXPORTER_PLUGIN_FILE', __FILE__);
define('WOO_EXPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_EXPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_EXPORTER_UPLOADS_DIR', WP_CONTENT_DIR . '/uploads/woo-exporter/');

// Require Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Main plugin class
 */
class Plugin {
    /**
     * Single instance of the class
     */
    private static ?Plugin $instance = null;

    /**
     * Get single instance
     */
    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - initialize plugin
     */
    private function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Initialize components
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Initialize plugin components
     */
    public function init(): void {
        // Load text domain for translations
        load_plugin_textdomain('woo-data-exporter', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize admin interface
        if (is_admin()) {
            new Admin\AdminPage();
            new Admin\AjaxHandler();
        }

        // Initialize cron worker
        new Cron\ExportWorker();
        
        // Initialize download handler
        new Download\FileHandler();
    }

    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active(): bool {
        return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true);
    }

    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice(): void {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('WooCommerce Advanced Data Exporter', 'woo-data-exporter'); ?></strong>
                <?php esc_html_e('wymaga zainstalowania i aktywowania wtyczki WooCommerce.', 'woo-data-exporter'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Create database table
        Database\Schema::create_tables();

        // Create uploads directory
        $upload_dir = WOO_EXPORTER_UPLOADS_DIR;
        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
            // Add .htaccess for security
            file_put_contents($upload_dir . '.htaccess', 'deny from all');
        }

        // Schedule cron event
        if (!wp_next_scheduled('woo_exporter_process_jobs')) {
            wp_schedule_event(time(), 'every_five_minutes', 'woo_exporter_process_jobs');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Unschedule cron event
        $timestamp = wp_next_scheduled('woo_exporter_process_jobs');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'woo_exporter_process_jobs');
        }
    }
}

// Add custom cron schedule (every 5 minutes)
add_filter('cron_schedules', function($schedules) {
    $schedules['every_five_minutes'] = [
        'interval' => 300, // 5 minutes in seconds
        'display'  => __('Co 5 minut', 'woo-data-exporter')
    ];
    return $schedules;
});

// Initialize the plugin
Plugin::instance();

