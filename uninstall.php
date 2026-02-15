<?php
/**
 * Uninstall GT TourOps Manager
 * Removes plugin options only (tables are preserved by default to avoid accidental data loss).
 * To drop tables, define GTTOM_DROP_TABLES_ON_UNINSTALL true in wp-config.php before uninstall.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('gttom_db_version');
delete_option('gttom_operator_dashboard_url');
delete_option('gttom_agent_dashboard_url');

if (!defined('GTTOM_DROP_TABLES_ON_UNINSTALL') || !GTTOM_DROP_TABLES_ON_UNINSTALL) {
    return;
}

global $wpdb;
$prefix = $wpdb->prefix . 'gttom_';
$tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $prefix . '%'));
foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS $t");
}
