<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

/**
 * ------------------------------------------------------------
 * Phase 10 — Automation & External Visibility (ONE ZIP)
 *
 * Modules implemented in this file:
 * 10.1 Ops reminders (internal) — derived counts + optional cron cache
 * 10.2 Supplier auto notifications on Booked/Paid (template-based)
 * 10.3 Supplier read-only portal (token-based)
 * 10.4 Ops timeline view (tour-level)
 * 10.5 Smart defaults & templates (minimal storage + UI)
 * ------------------------------------------------------------
 */
final class Phase10 {

    const CRON_HOOK = 'gttom_phase10_hourly';

    public static function init(): void {
        // Cron for reminders cache (safe even if never used).
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action(self::CRON_HOOK, [__CLASS__, 'cron_refresh_reminders']);

        // AJAX settings endpoints (operator/admin only)
        add_action('wp_ajax_gttom_p10_save_automation', [__CLASS__, 'ajax_save_automation']);
        add_action('wp_ajax_gttom_p10_generate_supplier_link', [__CLASS__, 'ajax_generate_supplier_link']);

        // Shortcodes
        add_shortcode('gttom_operator_timeline', [__CLASS__, 'sc_operator_timeline']);
        add_shortcode('gttom_operator_automation', [__CLASS__, 'sc_operator_automation']);
        add_shortcode('gttom_supplier_portal', [__CLASS__, 'sc_supplier_portal']);
    }

    public static function ensure_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * ------------------------------------------------------------
     * 10.1 Reminders
     * ------------------------------------------------------------
     */
    public static function cron_refresh_reminders(): void {
        // Compute for all active companies.
        global $wpdb;
        $companiesT = DB::table('companies');
        $rows = $wpdb->get_results("SELECT id FROM $companiesT WHERE status='active' ORDER BY id ASC", ARRAY_A);
        if (!$rows) return;
        foreach ($rows as $r) {
            $cid = (int)($r['id'] ?? 0);
            if ($cid < 1) continue;
            $data = self::compute_reminders($cid);
            update_option('gttom_p10_reminders_' . $cid, $data, false);
        }
    }

    public static function compute_reminders(int $company_id): array {
        global $wpdb;
        $toursT = DB::table('tours');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');

        // Not-ready: no suppliers OR status not_booked
        $not_ready = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.id)
             FROM $stepsT s
             INNER JOIN $toursT t ON t.id = s.tour_id
             LEFT JOIN $ssT ss ON ss.step_id = s.id
             WHERE t.company_id = %d
               AND t.status IN ('draft','in_progress')
               AND (s.status='not_booked' OR ss.id IS NULL)",
            $company_id
        ));

        // Pending too long: status pending and older than threshold since last update.
        // Company-scoped threshold (Phase 10.1 polish): avoids cross-company interference.
        $hours = (int) get_option('gttom_p10_pending_hours_' . $company_id, 48);
        if ($hours < 1) $hours = 48;
        $pending_stale = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM $stepsT s
             INNER JOIN $toursT t ON t.id = s.tour_id
             WHERE t.company_id = %d
               AND t.status IN ('draft','in_progress')
               AND s.status='pending'
               AND COALESCE(s.updated_at, s.created_at) < (NOW() - INTERVAL %d HOUR)",
            $company_id,
            $hours
        ));

        return [
            'company_id' => $company_id,
            'not_ready_steps' => $not_ready,
            'pending_stale_steps' => $pending_stale,
            'pending_stale_hours' => $hours,
            'generated_at' => current_time('mysql'),
        ];
    }

    /**
     * ------------------------------------------------------------
     * 10.2 Notification templates (stored in wp_options)
     * ------------------------------------------------------------
     */
    public static function get_templates(int $company_id): array {
        $opt = (array) get_option('gttom_p10_templates_' . $company_id, []);
        $defaults = [
            'booked_email_subject' => 'Booking confirmed: {tour_name} — {step_title}',
            'booked_email_body' => "Hello {supplier_name},\n\nThe booking is confirmed.\n\nTour: {tour_name}\nDate: {day_date}\nStep: {step_title}\nStatus: Booked\n\nThank you.",
            'booked_telegram_body' => "✅ <b>Booked</b>\nTour: {tour_name}\nDate: {day_date}\nStep: {step_title}",
            'paid_email_subject' => 'Payment confirmed: {tour_name} — {step_title}',
            'paid_email_body' => "Hello {supplier_name},\n\nPayment is confirmed.\n\nTour: {tour_name}\nDate: {day_date}\nStep: {step_title}\nStatus: Paid\n\nThank you.",
            'paid_telegram_body' => "💳 <b>Paid</b>\nTour: {tour_name}\nDate: {day_date}\nStep: {step_title}",
        ];
        return array_merge($defaults, $opt);
    }

    public static function render_template(string $tpl, array $ctx): string {
        foreach ($ctx as $k => $v) {
            $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
        }
        return $tpl;
    }

    /**
     * ------------------------------------------------------------
     * 10.3 Supplier portal token generation
     * ------------------------------------------------------------
     */
    public static function create_supplier_token(int $supplier_id, ?int $ttl_days = 30): string {
        global $wpdb;
        $t = DB::table('supplier_tokens');
        $token = wp_generate_password(40, false, false);
        $now = current_time('mysql');
        $expires = null;
        if ($ttl_days && $ttl_days > 0) {
            $expires = gmdate('Y-m-d H:i:s', time() + ($ttl_days * DAY_IN_SECONDS));
        }
        $wpdb->insert($t, [
            'supplier_id' => $supplier_id,
            'token' => $token,
            'expires_at' => $expires,
            'created_at' => $now,
        ], ['%d','%s','%s','%s']);
        return $token;
    }

    public static function resolve_supplier_by_token(string $token): ?int {
        $token = trim($token);
        if ($token === '') return null;
        global $wpdb;
        $t = DB::table('supplier_tokens');
        $supplier_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT supplier_id FROM $t WHERE token=%s AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1",
            $token
        ));
        return $supplier_id > 0 ? $supplier_id : null;
    }

    /**
     * ------------------------------------------------------------
     * AJAX: Save automation settings + templates
     * ------------------------------------------------------------
     */
    public static function ajax_save_automation(): void {
        check_ajax_referer('gttom_nonce', 'nonce');
        if (!current_user_can('gttom_operator_access') && !current_user_can('gttom_admin_access')) {
            wp_send_json_error(['message' => 'Access denied'], 403);
        }
        $company_id = (int) DB::current_company_id();
        if ($company_id < 1) wp_send_json_error(['message' => 'No company'], 400);

        $pending_hours = isset($_POST['pending_hours']) ? absint($_POST['pending_hours']) : 48;
        if ($pending_hours < 1) $pending_hours = 48;
        // Company-scoped threshold.
        update_option('gttom_p10_pending_hours_' . $company_id, $pending_hours, false);

        // Phase 10.2.1 — explicit toggles (makes automation behavior obvious to operators)
        $auto_enabled = isset($_POST['auto_enabled']) ? (absint($_POST['auto_enabled']) ? 1 : 0) : 1;
        $notify_booked = isset($_POST['notify_booked']) ? (absint($_POST['notify_booked']) ? 1 : 0) : 1;
        $notify_paid   = isset($_POST['notify_paid']) ? (absint($_POST['notify_paid']) ? 1 : 0) : 1;
        update_option('gttom_p10_automation_enabled_' . $company_id, $auto_enabled, false);
        update_option('gttom_p10_notify_booked_' . $company_id, $notify_booked, false);
        update_option('gttom_p10_notify_paid_' . $company_id, $notify_paid, false);

        $templates = isset($_POST['templates']) && is_array($_POST['templates']) ? $_POST['templates'] : [];
        $clean = [];
        foreach (['booked_email_subject','booked_email_body','booked_telegram_body','paid_email_subject','paid_email_body','paid_telegram_body'] as $k) {
            if (isset($templates[$k])) {
                $clean[$k] = wp_kses_post((string)$templates[$k]);
            }
        }
        update_option('gttom_p10_templates_' . $company_id, $clean, false);

        // Defaults placeholders (Phase 10.5 minimal)
        $defaults = isset($_POST['defaults']) && is_array($_POST['defaults']) ? $_POST['defaults'] : [];
        update_option('gttom_p10_defaults_' . $company_id, wp_json_encode($defaults), false);

        wp_send_json_success(['ok' => true]);
    }

    public static function ajax_generate_supplier_link(): void {
        check_ajax_referer('gttom_nonce', 'nonce');
        if (!current_user_can('gttom_operator_access') && !current_user_can('gttom_admin_access')) {
            wp_send_json_error(['message' => 'Access denied'], 403);
        }
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        if ($supplier_id < 1) wp_send_json_error(['message' => 'Missing supplier'], 400);
        $token = self::create_supplier_token($supplier_id, 30);
        $url = home_url('/supplier/?t=' . rawurlencode($token));
        wp_send_json_success(['url' => $url, 'token' => $token]);
    }

    /**
     * ------------------------------------------------------------
     * Shortcode: Operator Automation (10.1/10.2/10.5 UI)
     * ------------------------------------------------------------
     */
    public static function sc_operator_automation(): string {
        if (!function_exists(__NAMESPACE__ . '\\require_operator') || !\GTTOM\require_operator()) {
            return function_exists(__NAMESPACE__ . '\\denied') ? \GTTOM\denied() : 'Access denied';
        }
        if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
        $company_id = (int) DB::current_company_id();
        $rem = self::compute_reminders($company_id);
        $templates = self::get_templates($company_id);
        $pending_hours = (int) get_option('gttom_p10_pending_hours_' . $company_id, 48);
        if ($pending_hours < 1) $pending_hours = 48;

        // Actionable lists (Phase 10.1 polish): show a few concrete items to work on.
        $ops_base = function_exists('\\GTTOM\\operator_url') ? (string) \GTTOM\operator_url('tours') : home_url('/operator/');
        $ops_base = $ops_base ? $ops_base : home_url('/operator/');

        global $wpdb;
        $toursT = DB::table('tours');
        $daysT  = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');

        $not_ready_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS step_id, s.tour_id, t.name AS tour_name, d.day_date, d.day_index, s.title, s.step_type, s.status,
                    (SELECT COUNT(*) FROM $ssT ss WHERE ss.step_id=s.id) AS sup_count
             FROM $stepsT s
             INNER JOIN $toursT t ON t.id=s.tour_id
             LEFT JOIN $daysT d ON d.id=s.day_id
             WHERE t.company_id=%d
               AND t.status IN ('draft','in_progress')
               AND (s.status='not_booked' OR (SELECT COUNT(*) FROM $ssT ss2 WHERE ss2.step_id=s.id)=0)
             ORDER BY COALESCE(d.day_date,'9999-12-31') ASC, d.day_index ASC, s.step_index ASC
             LIMIT 20",
            $company_id
        ), ARRAY_A);

        $pending_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS step_id, s.tour_id, t.name AS tour_name, d.day_date, d.day_index, s.title, s.step_type, s.status,
                    (SELECT COUNT(*) FROM $ssT ss WHERE ss.step_id=s.id) AS sup_count,
                    COALESCE(s.updated_at, s.created_at) AS last_touch
             FROM $stepsT s
             INNER JOIN $toursT t ON t.id=s.tour_id
             LEFT JOIN $daysT d ON d.id=s.day_id
             WHERE t.company_id=%d
               AND t.status IN ('draft','in_progress')
               AND s.status='pending'
               AND COALESCE(s.updated_at, s.created_at) < (NOW() - INTERVAL %d HOUR)
             ORDER BY COALESCE(d.day_date,'9999-12-31') ASC, d.day_index ASC, s.step_index ASC
             LIMIT 20",
            $company_id,
            $pending_hours
        ), ARRAY_A);

        ob_start();
        ?>
        <div id="gttom-automation-root" class="gttom-automationRoot">
        <div class="gttom-card">
            <div class="gttom-mode-badge gttom-mode-badge--exec">
                <strong>⚙️ Execution Mode</strong>
                <span>Automation & notification rules (Phase 10)</span>
            </div>

            <?php
            // Phase 10.2.1: Proper, operator-friendly tabbed UI (no fragile JS DOM reshuffling).
            $auto_enabled = (int) get_option('gttom_p10_automation_enabled_' . $company_id, 1);
            $notify_booked = (int) get_option('gttom_p10_notify_booked_' . $company_id, 1);
            $notify_paid   = (int) get_option('gttom_p10_notify_paid_' . $company_id, 1);
            ?>

            <div class="gttom-note" style="margin-top:12px;">
                Automation never edits tours. It only <strong>reminds</strong> operators and <strong>sends</strong> supplier updates when a step becomes <strong>Booked</strong> / <strong>Paid</strong>.
                Silent edits always skip notifications.
            </div>

            <div class="gttom-tabs" id="gttom-p10-tabs" style="margin-top:12px;">
                <button type="button" class="gttom-tab is-active" data-tab="reminders">Ops Reminders</button>
                <button type="button" class="gttom-tab" data-tab="notifications">Supplier Notifications</button>
                <button type="button" class="gttom-tab" data-tab="tools">Tools & Rules</button>
            </div>

            <div class="gttom-p10-panels" style="margin-top:12px;">
                <!-- REMINDERS -->
                <div class="gttom-p10-panel is-active" data-panel="reminders">
                    <h3 style="margin:0 0 8px;">Ops Reminders (10.1)</h3>
                    <div class="gttom-muted" style="margin-bottom:10px;">Snapshot generated: <strong><?php echo esc_html((string)$rem['generated_at']); ?></strong></div>

                    <div class="gttom-kpis" style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0;">
                        <div class="gttom-kpi" style="padding:10px 12px;border:1px solid rgba(0,0,0,.08);border-radius:12px;min-width:180px;">
                            <div class="gttom-muted">Not ready steps</div>
                            <div style="font-size:22px;font-weight:700;">⚪ <?php echo (int)$rem['not_ready_steps']; ?></div>
                            <div class="gttom-muted" style="margin-top:4px;">Missing supplier or still Not booked</div>
                        </div>
                        <div class="gttom-kpi" style="padding:10px 12px;border:1px solid rgba(0,0,0,.08);border-radius:12px;min-width:180px;">
                            <div class="gttom-muted">Pending too long</div>
                            <div style="font-size:22px;font-weight:700;">🟡 <?php echo (int)$rem['pending_stale_steps']; ?></div>
                            <div class="gttom-muted" style="margin-top:4px;">Pending older than threshold</div>
                        </div>
                    </div>

                    <div class="gttom-form-grid" style="grid-template-columns: 320px;">
                        <label>Pending stale threshold (hours)
                            <input type="number" id="gttom-p10-pending-hours" value="<?php echo esc_attr((string)$pending_hours); ?>" min="1" />
                        </label>
                    </div>
                    <div class="gttom-muted" style="margin-top:4px;">Used only for reminders. Does not affect tour status.</div>

                    <div style="margin-top:14px;">
                        <h4 style="margin:0 0 8px;">⚪ Not-ready steps (top 20)</h4>
                        <?php if (!$not_ready_rows): ?>
                            <div class="gttom-muted">Nothing to show.</div>
                        <?php else: ?>
                            <div style="overflow:auto;">
                                <table class="gttom-table">
                                    <thead><tr><th>Tour</th><th>Day</th><th>Step</th><th>Status</th><th>Suppliers</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($not_ready_rows as $r):
                                        $tour_id = (int)($r['tour_id'] ?? 0);
                                        $u = add_query_arg(['tour_id' => $tour_id, 'view' => 'ops'], $ops_base);
                                        $supc = (int)($r['sup_count'] ?? 0);
                                    ?>
                                        <tr>
                                            <td><strong><?php echo esc_html((string)($r['tour_name'] ?? '')); ?></strong><div class="gttom-muted">#<?php echo (int)$tour_id; ?></div></td>
                                            <td><?php echo esc_html('Day ' . (int)($r['day_index'] ?? 0)); ?><div class="gttom-muted"><?php echo esc_html((string)($r['day_date'] ?? '')); ?></div></td>
                                            <td><?php echo esc_html((string)($r['title'] ?? '')); ?><div class="gttom-muted"><?php echo esc_html(strtoupper((string)($r['step_type'] ?? ''))); ?></div></td>
                                            <td><?php echo esc_html((string)($r['status'] ?? '')); ?></td>
                                            <td><?php echo (int)$supc; ?></td>
                                            <td><a class="gttom-btn gttom-btn-small" href="<?php echo esc_url($u); ?>">Open in Ops</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:14px;">
                        <h4 style="margin:0 0 8px;">🟡 Pending too long (older than <?php echo (int)$pending_hours; ?>h, top 20)</h4>
                        <?php if (!$pending_rows): ?>
                            <div class="gttom-muted">Nothing to show.</div>
                        <?php else: ?>
                            <div style="overflow:auto;">
                                <table class="gttom-table">
                                    <thead><tr><th>Tour</th><th>Day</th><th>Step</th><th>Last update</th><th>Suppliers</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($pending_rows as $r):
                                        $tour_id = (int)($r['tour_id'] ?? 0);
                                        $u = add_query_arg(['tour_id' => $tour_id, 'view' => 'ops'], $ops_base);
                                        $supc = (int)($r['sup_count'] ?? 0);
                                    ?>
                                        <tr>
                                            <td><strong><?php echo esc_html((string)($r['tour_name'] ?? '')); ?></strong><div class="gttom-muted">#<?php echo (int)$tour_id; ?></div></td>
                                            <td><?php echo esc_html('Day ' . (int)($r['day_index'] ?? 0)); ?><div class="gttom-muted"><?php echo esc_html((string)($r['day_date'] ?? '')); ?></div></td>
                                            <td><?php echo esc_html((string)($r['title'] ?? '')); ?><div class="gttom-muted"><?php echo esc_html(strtoupper((string)($r['step_type'] ?? ''))); ?></div></td>
                                            <td><?php echo esc_html((string)($r['last_touch'] ?? '')); ?></td>
                                            <td><?php echo (int)$supc; ?></td>
                                            <td><a class="gttom-btn gttom-btn-small" href="<?php echo esc_url($u); ?>">Open in Ops</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- NOTIFICATIONS -->
                <div class="gttom-p10-panel" data-panel="notifications">
                    <h3 style="margin:0 0 8px;">Supplier Auto Notifications (10.2)</h3>
                    <div class="gttom-note">Sent automatically only when a step status becomes <strong>Booked</strong> or <strong>Paid</strong>. Silent edits skip all notifications.</div>

                    <div class="gttom-form-grid" style="grid-template-columns: 1fr 1fr; max-width:520px;">
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" id="gttom-p10-auto-enabled" <?php echo $auto_enabled ? 'checked' : ''; ?> />
                            <span><strong>Enable automation</strong></span>
                        </label>
                        <div></div>
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" id="gttom-p10-notify-booked" <?php echo $notify_booked ? 'checked' : ''; ?> />
                            <span>Send on <strong>Booked</strong></span>
                        </label>
                        <label style="display:flex;gap:10px;align-items:center;">
                            <input type="checkbox" id="gttom-p10-notify-paid" <?php echo $notify_paid ? 'checked' : ''; ?> />
                            <span>Send on <strong>Paid</strong></span>
                        </label>
                    </div>

                    <div class="gttom-form-grid" style="grid-template-columns: 1fr;">
                        <label>Booked email subject
                            <input type="text" id="gttom-p10-booked-subj" value="<?php echo esc_attr((string)$templates['booked_email_subject']); ?>" />
                        </label>
                        <label>Booked email body
                            <textarea id="gttom-p10-booked-body" rows="6"><?php echo esc_textarea((string)$templates['booked_email_body']); ?></textarea>
                        </label>
                        <label>Booked Telegram message
                            <textarea id="gttom-p10-booked-tg" rows="4"><?php echo esc_textarea((string)$templates['booked_telegram_body']); ?></textarea>
                        </label>
                        <label>Paid email subject
                            <input type="text" id="gttom-p10-paid-subj" value="<?php echo esc_attr((string)$templates['paid_email_subject']); ?>" />
                        </label>
                        <label>Paid email body
                            <textarea id="gttom-p10-paid-body" rows="6"><?php echo esc_textarea((string)$templates['paid_email_body']); ?></textarea>
                        </label>
                        <label>Paid Telegram message
                            <textarea id="gttom-p10-paid-tg" rows="4"><?php echo esc_textarea((string)$templates['paid_telegram_body']); ?></textarea>
                        </label>
                    </div>

                    <div class="gttom-note" style="margin-top:10px;">
                        Placeholders: <code>{supplier_name}</code> <code>{tour_name}</code> <code>{step_title}</code> <code>{day_date}</code>
                    </div>
                </div>

                <!-- TOOLS -->
                <div class="gttom-p10-panel" data-panel="tools">
                    <h3 style="margin:0 0 8px;">Tools & Rules</h3>

                    <div class="gttom-note" style="margin-bottom:10px;">
                        <strong>Rules:</strong> Automation never changes tour data. It only sends messages when allowed.
                        Silent edits always skip sending.
                    </div>

                    <div class="gttom-actions" style="margin-top:10px;">
                        <button class="gttom-btn" id="gttom-p10-save">Save Settings</button>
                        <span class="gttom-muted" id="gttom-p10-save-msg"></span>
                    </div>

                    <div style="margin-top:16px; padding-top:16px; border-top:1px solid rgba(0,0,0,.06);">
                        <h4 style="margin:0 0 8px;">Supplier Portal Link (Read-only)</h4>
                        <div class="gttom-note">Generate a secure read-only link for a supplier (valid 30 days). You can also generate it from <strong>Catalog → Suppliers</strong> using the “Portal link” button.</div>
                        <div class="gttom-form-grid" style="margin-top:10px; max-width:320px;">
                            <label>Supplier ID
                                <input type="number" id="gttom-p10-supplier-id" min="1" placeholder="e.g. 12" />
                            </label>
                        </div>
                        <div class="gttom-actions">
                            <button class="gttom-btn gttom-btn-secondary" id="gttom-p10-gen-supplier-link">Generate Link</button>
                            <span class="gttom-muted" id="gttom-p10-gen-supplier-msg"></span>
                        </div>
                        <div id="gttom-p10-supplier-link" style="margin-top:8px; word-break:break-all;"></div>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php
        $inner = (string) ob_get_clean();
        if (function_exists('\\GTTOM\\operator_shell')) {
            return (string) \GTTOM\operator_shell('automation', 'Automation', $inner);
        }
        return $inner;
    }

    /**
     * ------------------------------------------------------------
     * Shortcode: Ops Timeline (10.4)
     * ------------------------------------------------------------
     */
    public static function sc_operator_timeline(): string {
        if (!function_exists(__NAMESPACE__ . '\\require_operator') || !\GTTOM\require_operator()) {
            return function_exists(__NAMESPACE__ . '\\denied') ? \GTTOM\denied() : 'Access denied';
        }
        if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }

        $company_id = (int) DB::current_company_id();
        $tour_id = isset($_GET['tour_id']) ? absint($_GET['tour_id']) : 0;

        // If no tour is selected, show a picker + recent tours list (Phase 10.2 UX Integration Fix).
        if ($tour_id < 1) {
            global $wpdb;
            $toursT = DB::table('tours');
            $tours = $wpdb->get_results($wpdb->prepare(
                "SELECT id,name,start_date,status FROM $toursT WHERE company_id=%d ORDER BY id DESC LIMIT 50",
                $company_id
            ), ARRAY_A);

            $base = function_exists('\\GTTOM\\operator_url') ? (string) \GTTOM\operator_url('timeline') : '';
            if (!$base) $base = home_url('/operator/');

            ob_start();
            ?>
            <div class="gttom-card">
                <div class="gttom-mode-badge gttom-mode-badge--exec">
                    <strong>⚙️ Execution Mode</strong>
                    <span>Timeline view (Phase 10.4)</span>
                </div>

                <h3 style="margin-top:14px;">Timeline</h3>
                <p class="gttom-note">Select a tour to open its operational timeline.</p>

                <div class="gttom-form-grid" style="grid-template-columns: 1fr; max-width:520px;">
                    <label>Choose tour
                        <select id="gttom-timeline-tour" onchange="if(this.value){window.location.href=this.value;}">
                            <option value="">— Select —</option>
                            <?php foreach (($tours ?: []) as $t):
                                $tid = (int)($t['id'] ?? 0);
                                $u = add_query_arg(['tour_id' => $tid], $base);
                                $label = (string)($t['name'] ?? ('Tour #' . $tid));
                                $meta = (string)($t['start_date'] ?? '—');
                                $st   = (string)($t['status'] ?? '');
                            ?>
                                <option value="<?php echo esc_url($u); ?>"><?php echo esc_html('#' . $tid . ' · ' . $label . ' · ' . $meta . ' · ' . $st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="margin-top:12px;">
                    <h4 style="margin:0 0 8px 0;">Recent tours</h4>
                    <?php if (!$tours): ?>
                        <div class="gttom-muted">No tours found.</div>
                    <?php else: ?>
                        <div style="overflow:auto;">
                            <table class="gttom-table">
                                <thead><tr><th>ID</th><th>Tour</th><th>Start</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($tours as $t):
                                    $tid = (int)($t['id'] ?? 0);
                                    $u = add_query_arg(['tour_id' => $tid], $base);
                                ?>
                                    <tr>
                                        <td>#<?php echo (int)$tid; ?></td>
                                        <td><?php echo esc_html((string)($t['name'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string)($t['start_date'] ?? '—')); ?></td>
                                        <td><?php echo esc_html((string)($t['status'] ?? '')); ?></td>
                                        <td><a class="gttom-btn gttom-btn-small" href="<?php echo esc_url($u); ?>">Open timeline</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            $inner = (string) ob_get_clean();
            if (function_exists('\\GTTOM\\operator_shell')) {
                return (string) \GTTOM\operator_shell('timeline', 'Timeline', $inner);
            }
            return $inner;
        }

        // Access guard (Phase 10.2.1): Timeline is a normal page render.
        // Do NOT call Ajax::assert_tour_owner() here because it's a private AJAX helper and will fatal.
        // Instead, enforce company-scoped access safely.
        global $wpdb;
        $toursT = DB::table('tours');
        $tour_company = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT company_id FROM $toursT WHERE id=%d",
            $tour_id
        ));
        if (!$tour_company) {
            $inner = '<div class="gttom-card">Tour not found.</div>';
            return function_exists('\\GTTOM\\operator_shell') ? (string) \GTTOM\operator_shell('timeline', 'Timeline', $inner) : $inner;
        }
        if (!current_user_can('gttom_admin_access')) {
            $cid = (int) DB::current_company_id();
            if (!$cid || $cid !== $tour_company) {
                $inner = '<div class="gttom-card">Access denied.</div>';
                return function_exists('\\GTTOM\\operator_shell') ? (string) \GTTOM\operator_shell('timeline', 'Timeline', $inner) : $inner;
            }
        }

        // Continue render
        $daysT  = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');

        $tour = $wpdb->get_row($wpdb->prepare("SELECT id,name,start_date,pax,status FROM $toursT WHERE id=%d", $tour_id), ARRAY_A);
        if (!$tour) return '<div class="gttom-card">Tour not found.</div>';

        $days = $wpdb->get_results($wpdb->prepare("SELECT * FROM $daysT WHERE tour_id=%d ORDER BY day_index ASC", $tour_id), ARRAY_A);
        $steps = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, (SELECT COUNT(*) FROM $ssT ss WHERE ss.step_id=s.id) AS sup_count
             FROM $stepsT s WHERE s.tour_id=%d ORDER BY s.day_id ASC, s.step_index ASC",
            $tour_id
        ), ARRAY_A);
        $stepsByDay = [];
        foreach ($steps as $s) {
            $did = (int)$s['day_id'];
            if (!isset($stepsByDay[$did])) $stepsByDay[$did] = [];
            $stepsByDay[$did][] = $s;
        }

        ob_start();
        ?>
        <div class="gttom-card">
            <div class="gttom-mode-badge gttom-mode-badge--exec">
                <strong>⚙️ Execution Mode</strong>
                <span>Timeline view (Phase 10.4)</span>
            </div>
            <h3 style="margin-top:14px;"><?php echo esc_html((string)$tour['name']); ?></h3>
            <div class="gttom-note">Tour ID: <?php echo (int)$tour['id']; ?> · Status: <strong><?php echo esc_html((string)$tour['status']); ?></strong> · Pax: <?php echo (int)$tour['pax']; ?></div>

            <?php foreach ($days as $d): $did = (int)$d['id']; ?>
                <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(0,0,0,.06);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <div>
                            <div style="font-weight:700;">Day <?php echo (int)$d['day_index']; ?><?php if (!empty($d['title'])) echo ' — ' . esc_html((string)$d['title']); ?></div>
                            <div class="gttom-muted"><?php echo esc_html((string)($d['day_date'] ?? '')); ?><?php if (!empty($d['city'])) echo ' · ' . esc_html((string)$d['city']); ?></div>
                        </div>
                    </div>

                    <?php $list = $stepsByDay[$did] ?? []; if (!$list): ?>
                        <div class="gttom-muted" style="margin-top:8px;">No steps.</div>
                    <?php else: ?>
                        <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($list as $s):
                                $sup = (int)($s['sup_count'] ?? 0);
                                $st = (string)($s['status'] ?? 'not_booked');
                                $dot = 'gttom-ready-0'; $emoji = '⚪';
                                if ($sup > 0 && $st === 'pending') { $dot='gttom-ready-1'; $emoji='🟡'; }
                                if ($sup > 0 && ($st === 'booked' || $st === 'paid')) { $dot='gttom-ready-2'; $emoji='🟢'; }
                            ?>
                            <div style="padding:10px 12px;border:1px solid rgba(0,0,0,.08);border-radius:12px;display:flex;gap:10px;align-items:flex-start;">
                                <span class="gttom-ready-dot <?php echo esc_attr($dot); ?>" aria-hidden="true"></span>
                                <div style="flex:1;">
                                    <div style="display:flex;justify-content:space-between;gap:10px;">
                                        <div style="font-weight:700;"><?php echo esc_html((string)$s['title']); ?></div>
                                        <div class="gttom-muted"><?php echo esc_html(strtoupper((string)$s['step_type'])); ?> · <?php echo esc_html((string)$st); ?> · <?php echo $emoji; ?></div>
                                    </div>
                                    <?php if (!empty($s['time'])): ?><div class="gttom-muted">Time: <?php echo esc_html((string)$s['time']); ?></div><?php endif; ?>
                                    <?php if (!empty($s['description'])): ?><div style="margin-top:6px;white-space:pre-wrap;"><?php echo esc_html((string)$s['description']); ?></div><?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $inner = (string) ob_get_clean();
        if (function_exists('\\GTTOM\\operator_shell')) {
            return (string) \GTTOM\operator_shell('timeline', 'Timeline', $inner);
        }
        return $inner;
    }

    /**
     * ------------------------------------------------------------
     * Shortcode: Supplier Portal (10.3)
     * ------------------------------------------------------------
     */
    public static function sc_supplier_portal(): string {
        if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
        $token = isset($_GET['t']) ? sanitize_text_field((string)$_GET['t']) : '';
        if (!$token) {
            return '<div class="gttom-card"><h3>Supplier Portal</h3><p class="gttom-note">Please open your unique link provided by the operator.</p></div>';
        }
        $supplier_id = self::resolve_supplier_by_token($token);
        if (!$supplier_id) {
            return '<div class="gttom-card"><h3>Supplier Portal</h3><p class="gttom-note">This link is invalid or expired.</p></div>';
        }

        global $wpdb;
        $supT  = DB::table('suppliers');
        $ssT   = DB::table('tour_step_suppliers');
        $stepsT= DB::table('tour_steps');
        $daysT = DB::table('tour_days');
        $toursT= DB::table('tours');

        $supplier = $wpdb->get_row($wpdb->prepare("SELECT id,name,email FROM $supT WHERE id=%d", $supplier_id), ARRAY_A);
        if (!$supplier) {
            return '<div class="gttom-card"><h3>Supplier Portal</h3><p class="gttom-note">Supplier not found.</p></div>';
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id AS tour_id, t.name AS tour_name, d.day_index, d.day_date, s.title, s.step_type, s.status, s.time
             FROM $ssT ss
             INNER JOIN $stepsT s ON s.id = ss.step_id
             INNER JOIN $toursT t ON t.id = s.tour_id
             INNER JOIN $daysT d ON d.id = s.day_id
             WHERE ss.supplier_id=%d
             ORDER BY d.day_date ASC, d.day_index ASC, s.step_index ASC
             LIMIT 500",
            $supplier_id
        ), ARRAY_A);

        ob_start();
        ?>
        <div class="gttom-card">
            <h3>Supplier Portal</h3>
            <div class="gttom-note">Welcome, <strong><?php echo esc_html((string)$supplier['name']); ?></strong>. This is a read-only view of your assigned steps.</div>
            <?php if (!$rows): ?>
                <div class="gttom-muted" style="margin-top:10px;">No assigned steps yet.</div>
            <?php else: ?>
                <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($rows as $r): ?>
                        <div style="padding:10px 12px;border:1px solid rgba(0,0,0,.08);border-radius:12px;">
                            <div style="display:flex;justify-content:space-between;gap:10px;">
                                <div style="font-weight:700;"><?php echo esc_html((string)$r['tour_name']); ?></div>
                                <div class="gttom-muted"><?php echo esc_html((string)$r['status']); ?></div>
                            </div>
                            <div class="gttom-muted">Day <?php echo (int)$r['day_index']; ?> · <?php echo esc_html((string)$r['day_date']); ?> · <?php echo esc_html(strtoupper((string)$r['step_type'])); ?><?php if (!empty($r['time'])) echo ' · ' . esc_html((string)$r['time']); ?></div>
                            <div style="margin-top:6px;white-space:pre-wrap;"><?php echo esc_html((string)$r['title']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

// Bootstrap
add_action('plugins_loaded', function () {
    if (class_exists('GTTOM\\Phase10')) {
        \GTTOM\Phase10::init();
    }
});
