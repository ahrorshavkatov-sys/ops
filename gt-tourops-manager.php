<?php
/**
 * Plugin Name: GT TourOps Manager
 * Description: Admin (wp-admin) controls plans/operators; Operators & Agents use frontend dashboards via shortcodes. AJAX-first pricing tiers.
 * Version: 7.0.0
 * Author: Guided Travels LTD
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: gttom
 */

if (!defined('ABSPATH')) exit;

// Guarded defines to prevent warnings/fatals if multiple copies of the plugin folder exist.
// The project rule is: keep ONLY one GT TourOps plugin folder installed at a time.
if (!defined('GTTOM_VERSION'))     define('GTTOM_VERSION', '7.0.0');
if (!defined('GTTOM_PLUGIN_FILE')) define('GTTOM_PLUGIN_FILE', __FILE__);
if (!defined('GTTOM_PLUGIN_DIR'))  define('GTTOM_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('GTTOM_PLUGIN_URL'))  define('GTTOM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Defaults for supplier Telegram notifications (can be overridden via wp_options).
// NOTE: Keep these guarded so site owners can override without editing files.
if (!defined('GTTOM_TELEGRAM_BOT_TOKEN')) define('GTTOM_TELEGRAM_BOT_TOKEN', '8206046161:AAGjg0hCdfOEqv3SSJw0KnxWEQVceqsDCK8');
if (!defined('GTTOM_TELEGRAM_BOT_LINK'))  define('GTTOM_TELEGRAM_BOT_LINK', 'https://t.me/gtopsmanagerbot');

require_once GTTOM_PLUGIN_DIR . 'includes/class-gttom-plugin.php';

register_activation_hook(__FILE__, ['GTTOM\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['GTTOM\\Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    GTTOM\Plugin::instance();
});
