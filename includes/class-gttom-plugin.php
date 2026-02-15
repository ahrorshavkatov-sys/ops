<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

final class Plugin {

    private static $instance = null;
    // Phase 6.0 introduces multi-tenant foundations: Companies + Company Users.
    // Increment when DB schema changes.
    // Phase 10 adds supplier portal tokens table (non-breaking new table).
    const DB_VERSION = '0.4.5';

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        require_once GTTOM_PLUGIN_DIR . 'includes/db.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/audit.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/capabilities.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/ajax.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/notifications.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/shortcodes.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/phase10.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/assets.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/admin/admin.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/security.php';

        add_action('init', [$this, 'load_textdomain']);

        // Stabilization: auto-run dbDelta upgrades when plugin updates.
        add_action('init', function () {
            $installed = get_option('gttom_db_version', '0');
            if (version_compare((string)$installed, self::DB_VERSION, '<')) {
                // Safe to call; dbDelta is idempotent.
                DB::install();
            }
        }, 10);

        // Stabilization: ensure roles/caps are present even if the role already existed.
        add_action('init', function () {
            if (function_exists('get_role')) {
                Capabilities::add_roles_and_caps();
                self::ensure_operator_pages();
                self::ensure_supplier_portal_page();
            }
        }, 11);

        Admin\Admin::instance();
        Ajax::instance(); // registers AJAX handlers in constructor
        Notifications::init();

        // Phase 10 modules (shortcodes, cron, supplier portal) must be initialized explicitly.
        if (class_exists('GTTOM\\Phase10')) {
            \GTTOM\Phase10::init();
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('gttom', false, dirname(plugin_basename(GTTOM_PLUGIN_FILE)) . '/languages');
    }

    public static function activate(): void {
        require_once GTTOM_PLUGIN_DIR . 'includes/db.php';
        require_once GTTOM_PLUGIN_DIR . 'includes/capabilities.php';

        // 0.3.4 includes a hard DB sync to resolve schema drift from earlier iterations.
        // This runs ONLY when upgrading from versions < 0.3.4 (dev-safe reset of gttom_* tables).
        $old = get_option('gttom_db_version', '0');
        $hard_reset = version_compare((string)$old, '0.3.4', '<');

        DB::install($hard_reset);
        Capabilities::add_roles_and_caps();
        self::ensure_operator_pages();
        self::ensure_supplier_portal_page();
        flush_rewrite_rules();
    }


    /**
     * Ensure required frontend pages exist so operator navigation doesn't 404.
     * Creates /operator/ (dashboard) and /operator/settings/ when missing.
     * Does NOT change DB schema.
     */
    private static function ensure_operator_pages(): void {
        // Only run in WP context
        if (!function_exists('get_page_by_path') || !function_exists('wp_insert_post')) return;

        // Respect admin overrides if they point to existing pages.
        $urls = (array) get_option('gttom_frontend_urls', []);
        if (!empty($urls['settings']) && is_string($urls['settings'])) {
            $pid = url_to_postid($urls['settings']);
            if ($pid) return; // settings page exists somewhere else
        }

        // Parent page: /operator/
        $parent = get_page_by_path('operator');
        $parent_id = $parent ? (int)$parent->ID : 0;
        if (!$parent_id) {
            $parent_id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Operator',
                'post_name'    => 'operator',
                'post_content' => '[gttom_operator_dashboard]',
            ]);
        }

        if (!$parent_id || is_wp_error($parent_id)) return;

        // Child page: /operator/settings/
        $settings = get_page_by_path('operator/settings');
        if (!$settings) {
            wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Settings',
                'post_name'    => 'settings',
                'post_parent'  => $parent_id,
                'post_content' => '[gttom_operator_settings]',
            ]);
        }

        // Child page: /operator/timeline/
        $timeline = get_page_by_path('operator/timeline');
        if (!$timeline) {
            wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Timeline',
                'post_name'    => 'timeline',
                'post_parent'  => $parent_id,
                'post_content' => '[gttom_operator_timeline]',
            ]);
        }

        // Child page: /operator/automation/
        $automation = get_page_by_path('operator/automation');
        if (!$automation) {
            wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Automation',
                'post_name'    => 'automation',
                'post_parent'  => $parent_id,
                'post_content' => '[gttom_operator_automation]',
            ]);
        }
    }

    /**
     * Phase 10.3 — Supplier read-only portal page.
     */
    private static function ensure_supplier_portal_page(): void {
        if (!function_exists('get_page_by_path') || !function_exists('wp_insert_post')) return;
        $p = get_page_by_path('supplier');
        if ($p) return;
        wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Supplier Portal',
            'post_name'    => 'supplier',
            'post_content' => '[gttom_supplier_portal]',
        ]);
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
