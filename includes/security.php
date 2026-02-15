<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

/**
 * Frontend-only requirement:
 * - Operators and Agents should not access wp-admin.
 * - If they try, redirect them to frontend dashboards.
 *
 * Admins keep normal wp-admin access.
 */
add_action('admin_init', function () {

    if (current_user_can('administrator') || current_user_can('gttom_admin_access')) return;
    if (!(current_user_can('gttom_operator_access') || current_user_can('gttom_agent_access'))) return;

    // Allow admin-ajax.php
    $pagenow = $GLOBALS['pagenow'] ?? '';
    if ($pagenow === 'admin-ajax.php') return;

    $operator_url = get_option('gttom_operator_dashboard_url', home_url('/operator/'));
    $agent_url    = get_option('gttom_agent_dashboard_url', home_url('/agent/'));

    if (current_user_can('gttom_operator_access')) {
        wp_safe_redirect($operator_url);
        exit;
    }

    if (current_user_can('gttom_agent_access')) {
        wp_safe_redirect($agent_url);
        exit;
    }
}, 1);

// Optionally hide admin bar for operator/agent
add_filter('show_admin_bar', function ($show) {
    if (current_user_can('gttom_operator_access') || current_user_can('gttom_agent_access')) return false;
    return $show;
});
