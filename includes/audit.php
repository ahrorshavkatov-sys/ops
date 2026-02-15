<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

/**
 * Phase 9.1 — Ops Audit Trail
 *
 * Minimal, append-only audit log for operational accountability.
 * - Tracks ONLY: status changes + supplier assign/remove/change
 * - Read-only consumer (Ops Console)
 * - Never mixed into Description or Internal Notes
 */
final class Audit {

    /**
     * Insert a new audit event.
     */
    public static function log(int $company_id, int $tour_id, int $step_id, string $action, string $old_value = '', string $new_value = '', array $meta = [], ?int $changed_by = null): void {
        if ($company_id <= 0 || $tour_id <= 0 || $step_id <= 0 || $action === '') return;

        global $wpdb;
        $t = DB::table('audit_log');
        if (!$t) return;

        $uid = $changed_by !== null ? (int)$changed_by : (int)get_current_user_id();
        $now = current_time('mysql');

        // Keep meta small and safe.
        $meta_json = '';
        if (!empty($meta)) {
            $meta_json = wp_json_encode($meta);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($t, [
            'company_id' => $company_id,
            'tour_id'    => $tour_id,
            'step_id'    => $step_id,
            'action'     => $action,
            'old_value'  => $old_value,
            'new_value'  => $new_value,
            'meta_json'  => $meta_json,
            'changed_by' => $uid,
            'created_at' => $now,
        ], ['%d','%d','%d','%s','%s','%s','%s','%d','%s']);
    }

    /**
     * Fetch latest audit events for a step.
     */
    public static function get_step_events(int $company_id, int $step_id, int $limit = 80): array {
        if ($company_id <= 0 || $step_id <= 0) return [];
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $t = DB::table('audit_log');
        if (!$t) return [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT action, old_value, new_value, meta_json, changed_by, created_at
             FROM $t
             WHERE company_id=%d AND step_id=%d
             ORDER BY created_at DESC, id DESC
             LIMIT %d",
            $company_id,
            $step_id,
            $limit
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $uid = (int)($r['changed_by'] ?? 0);
            $u = $uid ? get_userdata($uid) : null;
            $who = $u ? $u->display_name : ($uid ? ('User ' . $uid) : 'System');

            $reason = '';
            $mj = (string)($r['meta_json'] ?? '');
            if ($mj !== '') {
                $m = json_decode($mj, true);
                if (is_array($m) && !empty($m['reason'])) {
                    $reason = sanitize_text_field((string)$m['reason']);
                }
            }

            $out[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'action' => (string)($r['action'] ?? ''),
                'old' => (string)($r['old_value'] ?? ''),
                'new' => (string)($r['new_value'] ?? ''),
                'who' => (string)$who,
                'reason' => $reason,
            ];
        }
        return $out;
    }

    /**
     * Create a human-readable message for UI.
     */
    public static function ui_message(array $e): string {
        $action = (string)($e['action'] ?? '');
        $old = (string)($e['old'] ?? '');
        $new = (string)($e['new'] ?? '');
        $who = (string)($e['who'] ?? '');
        $reason = trim((string)($e['reason'] ?? ''));

        $suffix = $reason !== '' ? (' — Reason: ' . $reason) : '';

        switch ($action) {
            case 'status_changed':
                return sprintf('%s changed status: %s → %s%s', $who, $old ?: '—', $new ?: '—', $suffix);
            case 'supplier_assigned':
                return sprintf('%s assigned supplier: %s%s', $who, $new ?: '—', $suffix);
            case 'supplier_removed':
                return sprintf('%s removed supplier: %s%s', $who, $old ?: '—', $suffix);
            case 'supplier_changed':
                return sprintf('%s changed supplier: %s → %s%s', $who, $old ?: '—', $new ?: '—', $suffix);
            default:
                // Fallback
                if ($old !== '' || $new !== '') {
                    return sprintf('%s %s: %s → %s%s', $who, $action, $old ?: '—', $new ?: '—', $suffix);
                }
                return sprintf('%s %s%s', $who, $action, $suffix);
        }
    }
}
