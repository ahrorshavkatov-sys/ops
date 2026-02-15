<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

class Assets {

    private static function post_has_gttom($post): bool {
        if (!$post) return false;
        $content = (string) $post->post_content;
        if (strpos($content, '[gttom_') !== false) return true;
        // Elementor stores shortcode content in _elementor_data; scan lightly to avoid heavy loads.
        $edata = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($edata) && strpos($edata, 'gttom_') !== false) return true;
        return false;
    }

    public static function ensure(): void {
        if (wp_style_is('gttom-frontend', 'enqueued') && wp_script_is('gttom-frontend', 'enqueued')) {
            return;
        }
        wp_enqueue_style('gttom-frontend', GTTOM_PLUGIN_URL . 'assets/frontend.css', [], GTTOM_VERSION);
        wp_enqueue_script('gttom-frontend', GTTOM_PLUGIN_URL . 'assets/frontend.js', ['jquery'], GTTOM_VERSION, true);


        $company = DB::current_company();
        $company_name = $company && !empty($company['name']) ? $company['name'] : '';
        $company_logo = $company && !empty($company['logo_url']) ? $company['logo_url'] : '';
        wp_localize_script('gttom-frontend', 'GTTOM', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('gttom_nonce'),
            'companyName' => $company_name,
            'companyLogo' => $company_logo,
        ]);
    }

    public static function init(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend']);
    }

    public static function enqueue_frontend(): void {
        if (!is_singular()) return;

        global $post;
        if (!$post) return;

        $content = (string) $post->post_content;

        // Only load on pages containing our shortcodes (supports Elementor templates)
        if (!self::post_has_gttom($post)) return;

        wp_enqueue_style('gttom-frontend', GTTOM_PLUGIN_URL . 'assets/frontend.css', [], GTTOM_VERSION);
        wp_enqueue_script('gttom-frontend', GTTOM_PLUGIN_URL . 'assets/frontend.js', ['jquery'], GTTOM_VERSION, true);


        $company = DB::current_company();
        $company_name = $company && !empty($company['name']) ? $company['name'] : '';
        $company_logo = $company && !empty($company['logo_url']) ? $company['logo_url'] : '';

        wp_localize_script('gttom-frontend', 'GTTOM', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('gttom_nonce'),
            'companyName' => $company_name,
            'companyLogo' => $company_logo,
        ]);
    }
}

Assets::init();
