<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

class Ajax {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_gttom_add_service_tier', [$this, 'add_service_tier']);
        add_action('wp_ajax_gttom_delete_service_tier', [$this, 'delete_service_tier']);
        add_action('wp_ajax_gttom_list_service_tiers', [$this, 'list_service_tiers']);

        // Phase 1+2: Tours → Days → Steps (AJAX only)
        add_action('wp_ajax_gttom_tour_save_general', [$this, 'tour_save_general']);
        add_action('wp_ajax_gttom_tour_get', [$this, 'tour_get']);
        add_action('wp_ajax_gttom_day_add', [$this, 'day_add']);
        add_action('wp_ajax_gttom_day_update', [$this, 'day_update']);
        add_action('wp_ajax_gttom_day_delete', [$this, 'day_delete']);
        add_action('wp_ajax_gttom_day_reorder', [$this, 'day_reorder']);

        add_action('wp_ajax_gttom_step_add', [$this, 'step_add']);
        add_action('wp_ajax_gttom_step_update', [$this, 'step_update']);
        add_action('wp_ajax_gttom_step_delete', [$this, 'step_delete']);
        add_action('wp_ajax_gttom_step_reorder', [$this, 'step_reorder']);

        // Phase 2.2: Operator Admin Panel (Catalog)
        add_action('wp_ajax_gttom_catalog_list', [$this, 'catalog_list']);
        add_action('wp_ajax_gttom_catalog_save', [$this, 'catalog_save']);
        add_action('wp_ajax_gttom_catalog_delete', [$this, 'catalog_delete']);
        add_action('wp_ajax_gttom_catalog_toggle', [$this, 'catalog_toggle']);

        // Phase 2.3: Supplier attachment helpers
        add_action('wp_ajax_gttom_catalog_compact', [$this, 'catalog_compact']);
        add_action('wp_ajax_gttom_step_set_supplier', [$this, 'step_set_supplier']);
        // Phase 5.1: Multi-supplier per step (suppliers catalog)
        add_action('wp_ajax_gttom_step_add_supplier', [$this, 'step_add_supplier']);
        add_action('wp_ajax_gttom_step_remove_supplier', [$this, 'step_remove_supplier']);

        // Phase 8: Operational Intelligence (step activity timeline)
        add_action('wp_ajax_gttom_step_activity', [$this, 'step_activity']);

        // Phase 9.1: Ops Audit Trail (minimal)
        add_action('wp_ajax_gttom_step_audit', [$this, 'step_audit']);

        /**
         * Phase 3: Execution & Status system
         * - Operators + assigned Agents can update step status
         * - Only Operators can mark paid
         * - All status changes are logged
         */
        add_action('wp_ajax_gttom_step_set_status', [$this, 'step_set_status']);
        add_action('wp_ajax_gttom_step_set_notes', [$this, 'step_set_notes']);

        // Phase 3: Agent execution view
        add_action('wp_ajax_gttom_agent_my_tours', [$this, 'agent_my_tours']);
        add_action('wp_ajax_gttom_agent_tour_get', [$this, 'agent_tour_get']);

        // Phase 3: Minimal Agents management (operator)
                // Phase 4: Tours List & Health Radar
        add_action('wp_ajax_gttom_operator_tours_list', [$this, 'operator_tours_list']);
        add_action('wp_ajax_gttom_operator_tour_health_details', [$this, 'operator_tour_health_details']);
        add_action('wp_ajax_gttom_tour_soft_delete', [$this, 'tour_soft_delete']);
        add_action('wp_ajax_gttom_tour_hard_delete', [$this, 'tour_hard_delete']);

add_action('wp_ajax_gttom_tour_set_status', [$this, 'tour_set_status']);
add_action('wp_ajax_gttom_tour_notes_list', [$this, 'tour_notes_list']);
add_action('wp_ajax_gttom_tour_notes_add', [$this, 'tour_notes_add']);
add_action('wp_ajax_gttom_tour_notes_delete', [$this, 'tour_notes_delete']);

add_action('wp_ajax_gttom_operator_agents_list', [$this, 'operator_agents_list']);
        add_action('wp_ajax_gttom_operator_agents_add', [$this, 'operator_agents_add']);
        add_action('wp_ajax_gttom_operator_assign_agent', [$this, 'operator_assign_agent']);

        // Telegram (Option A): settings + supplier connect/disconnect
        add_action('wp_ajax_gttom_telegram_save_settings', [$this, 'telegram_save_settings']);
        add_action('wp_ajax_gttom_telegram_set_webhook', [$this, 'telegram_set_webhook']);
        add_action('wp_ajax_gttom_telegram_webhook_info', [$this, 'telegram_webhook_info']);
        add_action('wp_ajax_gttom_supplier_tg_generate', [$this, 'supplier_tg_generate']);
        add_action('wp_ajax_gttom_supplier_tg_disconnect', [$this, 'supplier_tg_disconnect']);
    }

    /**
     * Nonce helper used by handlers.
     */
    private static function verify_nonce(): void {
        check_ajax_referer('gttom_nonce', 'nonce');
    }

    /**
     * Recalculate day dates from tour start_date for days that are NOT manually overridden.
     */
    private static function recalc_day_dates(int $tour_id, ?string $start_date_sql): void {
        if (!$tour_id) return;
        if (!$start_date_sql) return;
        global $wpdb;
        $daysT = DB::table('tour_days');

        $days = $wpdb->get_results($wpdb->prepare(
            "SELECT id, day_index, date_override FROM $daysT WHERE tour_id=%d ORDER BY day_index ASC",
            $tour_id
        ), ARRAY_A);

        foreach ($days as $d) {
            if ((int)$d['date_override'] === 1) continue;
            $idx = max(1, (int)$d['day_index']);
            $date = date('Y-m-d', strtotime($start_date_sql . ' +' . ($idx - 1) . ' days'));
            $wpdb->update($daysT, ['day_date' => $date, 'updated_at' => current_time('mysql')], ['id' => (int)$d['id']], ['%s','%s'], ['%d']);
        }
    }

    public static function current_operator_id(): int {
        $user_id = get_current_user_id();
        if (!$user_id) return 0;
        global $wpdb;
        $table = DB::table('operators');
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d", $user_id));
        if ($existing) return $existing;

        // If user has operator access but no operator row exists yet, auto-provision.
        if (current_user_can('gttom_operator_access') || current_user_can('gttom_admin_access')) {
            $now = current_time('mysql');
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'company_name' => null,
                'phone' => null,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d','%s','%s','%s','%s','%s']);
            return (int) $wpdb->insert_id;
        }

        return 0;
    }

    /**
     * Current agent record for logged in user.
     * Agents are created/owned by an operator (via Operator dashboard).
     */
    public static function current_agent_row(): array {
        $user_id = get_current_user_id();
        if (!$user_id) return [];
        global $wpdb;
        $table = DB::table('agents');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d AND is_active=1", $user_id), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    private static function require_agent_or_admin(): void {
        if (!current_user_can('gttom_agent_access') && !current_user_can('gttom_admin_access')) {
            wp_send_json_error(['message' => __('Access denied', 'gttom')], 403);
        }
        check_ajax_referer('gttom_nonce', 'nonce');
    }

    private static function assert_agent_assigned(int $tour_id): void {
        if (current_user_can('gttom_admin_access')) return;
        $agent = self::current_agent_row();
        if (!$agent || empty($agent['id'])) {
            wp_send_json_error(['message' => __('Agent not found', 'gttom')], 403);
        }
        global $wpdb;
        $ta = DB::table('tour_agents');
        $ok = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $ta WHERE tour_id=%d AND agent_id=%d",
            $tour_id,
            (int)$agent['id']
        ));
        if (!$ok) {
            wp_send_json_error(['message' => __('Not assigned to this tour', 'gttom')], 403);
        }

        // Phase 6.1: company match (agents are company members)
        $toursT = DB::table('tours');
        $tour_company = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT company_id FROM $toursT WHERE id=%d",
            $tour_id
        ));
        $agent_company = self::current_company_id();
        if ($tour_company && $agent_company && $tour_company !== $agent_company) {
            wp_send_json_error(['message' => __('Not allowed', 'gttom')], 403);
        }
    }

    private static function require_access(): void {
        if (!current_user_can('gttom_operator_access') && !current_user_can('gttom_admin_access')) {
            wp_send_json_error(['message' => __('Access denied', 'gttom')], 403);
        }
        check_ajax_referer('gttom_nonce', 'nonce');
    }

    private static function require_operator_or_admin(): void {
        self::require_access();
        // require_access already enforces operator/admin.
    }

    /**
     * Phase 6.1: current company context for the current user.
     */
    private static function current_company_id(): int {
        $uid = get_current_user_id();
        if (!$uid) return 0;
        if (class_exists('GTTOM\\DB')) {
            return (int) \GTTOM\DB::current_company_id($uid);
        }
        return (int) get_user_meta($uid, 'gttom_current_company_id', true);
    }

    private static function assert_tour_owner(int $tour_id): array {
        global $wpdb;
        $tours = DB::table('tours');
        $tour = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tours WHERE id=%d", $tour_id), ARRAY_A);
        if (!$tour) {
            wp_send_json_error(['message' => __('Tour not found', 'gttom')], 404);
        }

        if (current_user_can('gttom_admin_access')) return $tour;

        // Phase 6.1: company-scoped access (operators in same company share tours)
        $company_id = self::current_company_id();
        if (!$company_id) {
            wp_send_json_error(['message' => __('Company not set. Ask admin to assign you to a company.', 'gttom')], 403);
        }
        if (!isset($tour['company_id']) || (int)$tour['company_id'] !== (int)$company_id) {
            wp_send_json_error(['message' => __('Not allowed', 'gttom')], 403);
        }
        return $tour;
    }

    private static function sanitize_currency($v): string {
        $v = strtoupper(trim((string)$v));
        if (!preg_match('/^[A-Z]{3}$/', $v)) return 'USD';
        return $v;
    }

    private static function assert_service_owner(int $service_id): void {
        if (current_user_can('gttom_admin_access')) return;

        global $wpdb;
        $operator_id = self::current_operator_id();
        if (!$operator_id) {
            wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);
        }

        $services = DB::table('services');
        $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT operator_id FROM $services WHERE id=%d", $service_id));
        if ($owner !== (int)$operator_id) {
            wp_send_json_error(['message' => __('Not allowed', 'gttom')], 403);
        }
    }

    public function add_service_tier(): void {
        self::require_access();

        $service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
        $min_pax    = isset($_POST['min_pax']) ? absint($_POST['min_pax']) : 0;
        $max_pax    = isset($_POST['max_pax']) ? absint($_POST['max_pax']) : 0;
        $price      = isset($_POST['price']) ? (float) $_POST['price'] : 0.0;

        if (!$service_id || !$min_pax || !$max_pax || $min_pax > $max_pax) {
            wp_send_json_error(['message' => __('Invalid tier data', 'gttom')], 400);
        }

        self::assert_service_owner($service_id);

        global $wpdb;
        $tiers = DB::table('service_tiers');
        $now = current_time('mysql');

        $ok = $wpdb->insert($tiers, [
            'service_id' => $service_id,
            'min_pax'    => $min_pax,
            'max_pax'    => $max_pax,
            'price'      => $price,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%d','%f','%s','%s']);

        if (!$ok) {
            $debug = [];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $debug['db_error'] = $wpdb->last_error;
            }
            wp_send_json_error(['message' => __('DB insert failed', 'gttom'), 'debug' => $debug], 500);
        }

        wp_send_json_success(['id' => (int)$wpdb->insert_id]);
    }

    public function delete_service_tier(): void {
        self::require_access();

        $tier_id = isset($_POST['tier_id']) ? absint($_POST['tier_id']) : 0;
        if (!$tier_id) wp_send_json_error(['message' => __('Missing tier id', 'gttom')], 400);

        global $wpdb;
        $tiers = DB::table('service_tiers');

        if (!current_user_can('gttom_admin_access')) {
            $services = DB::table('services');
            $operator_id = self::current_operator_id();

            $owner = (int) $wpdb->get_var($wpdb->prepare("
                SELECT s.operator_id
                FROM $tiers t
                INNER JOIN $services s ON s.id = t.service_id
                WHERE t.id=%d
            ", $tier_id));

            if ($owner !== (int)$operator_id) {
                wp_send_json_error(['message' => __('Not allowed', 'gttom')], 403);
            }
        }

        $wpdb->delete($tiers, ['id' => $tier_id], ['%d']);
        wp_send_json_success(['deleted' => true]);
    }

    public function list_service_tiers(): void {
        self::require_access();

        $service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
        if (!$service_id) wp_send_json_error(['message' => __('Missing service id', 'gttom')], 400);

        self::assert_service_owner($service_id);

        global $wpdb;
        $tiers = DB::table('service_tiers');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, min_pax, max_pax, price FROM $tiers WHERE service_id=%d ORDER BY min_pax ASC",
            $service_id
        ), ARRAY_A);

        wp_send_json_success(['tiers' => $rows]);
    }

    /**
     * ------------------------------------------------------------
     * Phase 2: Build Tour → General
     * ------------------------------------------------------------
     */
    public function tour_save_general(): void {
        self::require_operator_or_admin();

        global $wpdb;
        $tours = DB::table('tours');

        $tour_id   = isset($_POST['tour_id']) ? absint($_POST['tour_id']) : 0;
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        // Guard against accidental "slashed" apostrophes showing as \' in UI.
        $name = str_replace(["\\'", "\\\""], ["'", '"'], $name);
        // Guard against accidental "slashed" apostrophes coming from older UI payloads.
        $name = str_replace(["\\'", "\\\""], ["'", '"'], $name);
        $startDate = sanitize_text_field($_POST['start_date'] ?? '');
        $pax       = isset($_POST['pax']) ? absint($_POST['pax']) : 1;
        $currency  = self::sanitize_currency($_POST['currency'] ?? 'USD');
        $vat_rate  = isset($_POST['vat_rate']) ? (float) $_POST['vat_rate'] : 0.0;
        $status    = sanitize_text_field($_POST['status'] ?? 'draft');
        if (!in_array($status, ['draft','in_progress','completed'], true)) $status = 'draft';

        if (!$name) {
            wp_send_json_error(['message' => __('Tour name is required', 'gttom')], 400);
        }
        if ($pax < 1) $pax = 1;
        if ($vat_rate < 0) $vat_rate = 0;

        $operator_id = self::current_operator_id();
        if (!current_user_can('gttom_admin_access') && !$operator_id) {
            wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);
        }

        $now = current_time('mysql');

        // Normalize date
        $start_date_sql = null;
        if ($startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $start_date_sql = $startDate;
        }

        if ($tour_id) {
            $tour = self::assert_tour_owner($tour_id);
            $wpdb->update($tours, [
                'name' => $name,
                'start_date' => $start_date_sql,
                'pax' => $pax,
                'currency' => $currency,
                'vat_rate' => $vat_rate,
                'status' => $status,
                'updated_at' => $now,
            ], ['id' => $tour_id], ['%s','%s','%d','%s','%f','%s','%s'], ['%d']);

            // If start_date provided, auto-calculate day dates for non-overridden days
            if ($start_date_sql) {
                self::recalc_day_dates($tour_id, $start_date_sql);
            }

            wp_send_json_success(['tour_id' => (int)$tour_id]);
        }

        // Create
        $company_id = self::current_company_id();
        if (!current_user_can('gttom_admin_access') && !$company_id) {
            wp_send_json_error(['message' => __('Company not set. Ask admin to assign you to a company.', 'gttom')], 403);
        }

        $ok = $wpdb->insert($tours, [
            'company_id' => $company_id ?: 0,
            'operator_id' => $operator_id ?: 0,
            'name' => $name,
            'start_date' => $start_date_sql,
            'pax' => $pax,
            'currency' => $currency,
            'vat_rate' => $vat_rate,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%s','%s','%d','%s','%f','%s','%s','%s']);

        if (!$ok) {
            $debug = [];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $debug['db_error'] = $wpdb->last_error;
            }
            wp_send_json_error(['message' => __('DB insert failed', 'gttom'), 'debug' => $debug], 500);
        }
        $new_id = (int)$wpdb->insert_id;
        if ($start_date_sql) {
            self::recalc_day_dates($new_id, $start_date_sql);
        }
        wp_send_json_success(['tour_id' => $new_id]);
    }

    public function tour_get(): void {
        self::require_operator_or_admin();
        $tour_id = isset($_POST['tour_id']) ? absint($_POST['tour_id']) : 0;
        if (!$tour_id) wp_send_json_error(['message' => __('Missing tour id', 'gttom')], 400);

        global $wpdb;
        $tour = self::assert_tour_owner($tour_id);

        $daysT  = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');

        $days = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $daysT WHERE tour_id=%d ORDER BY day_index ASC",
            $tour_id
        ), ARRAY_A);

        $day_ids = array_map(fn($d) => (int)$d['id'], $days);
        $steps_by_day = [];
        if ($day_ids) {
            $in = implode(',', array_fill(0, count($day_ids), '%d'));
            $sql = $wpdb->prepare("SELECT * FROM $stepsT WHERE day_id IN ($in) ORDER BY day_id ASC, step_index ASC", $day_ids);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            foreach ($rows as $r) {
                $did = (int)$r['day_id'];
                if (!isset($steps_by_day[$did])) $steps_by_day[$did] = [];
                $steps_by_day[$did][] = $r;
            }
        }


// Assigned agent (if any)
$taT     = DB::table('tour_agents');
$agentsT = DB::table('agents');
$agent = $wpdb->get_row($wpdb->prepare(
    "SELECT a.id AS agent_id, COALESCE(a.display_name, a.email) AS agent_name
     FROM $taT ta
     LEFT JOIN $agentsT a ON a.id=ta.agent_id
     WHERE ta.tour_id=%d
     ORDER BY ta.id DESC
     LIMIT 1",
    $tour_id
), ARRAY_A);
if ($agent) {
    $tour['agent_id'] = (int)$agent['agent_id'];
    $tour['agent_name'] = (string)$agent['agent_name'];
} else {
    $tour['agent_id'] = 0;
    $tour['agent_name'] = '';
}

        // Normalize common text fields (avoid showing legacy escaped apostrophes like \' ).
        if (isset($tour['name'])) {
            $tour['name'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$tour['name']));
        }
        foreach ($days as &$d) {
            if (isset($d['title'])) $d['title'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$d['title']));
            if (isset($d['notes'])) $d['notes'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$d['notes']));
        }
        unset($d);
        foreach ($steps_by_day as &$arr) {
            foreach ($arr as &$s) {
                if (isset($s['title'])) $s['title'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$s['title']));
                if (isset($s['description'])) $s['description'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$s['description']));
                if (isset($s['notes'])) $s['notes'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$s['notes']));
            }
            unset($s);
        }
        unset($arr);

        

        // Phase 5.1: Attach multi-suppliers per step
        $operator_id = self::current_operator_id();
        $all_step_ids = [];
        foreach ($steps_by_day as $arr) {
            foreach ($arr as $s) {
                $all_step_ids[] = (int)($s['id'] ?? 0);
            }
        }
        $suppliers_by_step = self::step_suppliers_map($all_step_ids, (int)$operator_id);
        foreach ($steps_by_day as &$arr) {
            foreach ($arr as &$s) {
                $sid = (int)($s['id'] ?? 0);
                $s['suppliers'] = isset($suppliers_by_step[$sid]) ? $suppliers_by_step[$sid] : [];
            }
            unset($s);
        }
        unset($arr);

        // Phase 8: Attach latest supplier-request / delivery info per step+supplier (for icons & tooltips)
        $requests_by_step = [];
        $company_id = (int)($tour['company_id'] ?? self::current_company_id());
        if ($company_id > 0 && !empty($all_step_ids)) {
            $reqT = DB::table('step_supplier_requests');
            $in = implode(',', array_fill(0, count($all_step_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT step_id, supplier_id, channel, expires_at, responded_at, response, created_at\n                 FROM $reqT\n                 WHERE company_id=%d AND step_id IN ($in)\n                 ORDER BY created_at DESC",
                array_merge([$company_id], $all_step_ids)
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            foreach ($rows as $r) {
                $sid = (int)$r['step_id'];
                $supid = (int)$r['supplier_id'];
                if (!isset($requests_by_step[$sid])) $requests_by_step[$sid] = [];
                // Keep latest per supplier only (already ordered DESC)
                if (isset($requests_by_step[$sid][$supid])) continue;
                $requests_by_step[$sid][$supid] = [
                    'channel' => (string)($r['channel'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'expires_at' => (string)($r['expires_at'] ?? ''),
                    'responded_at' => (string)($r['responded_at'] ?? ''),
                    'response' => (string)($r['response'] ?? ''),
                ];
            }
        }
wp_send_json_success([
            'tour' => $tour,
            'days' => $days,
            'steps_by_day' => $steps_by_day,
            'requests_by_step' => $requests_by_step,
        ]);
    }

    /**
     * ------------------------------------------------------------
     * Phase 2: Days CRUD
     * ------------------------------------------------------------
     */
    public function day_add(): void {
        self::require_operator_or_admin();
        $tour_id = isset($_POST['tour_id']) ? absint($_POST['tour_id']) : 0;
        if (!$tour_id) wp_send_json_error(['message' => __('Missing tour id', 'gttom')], 400);
        self::assert_tour_owner($tour_id);

        $day_type = sanitize_text_field($_POST['day_type'] ?? 'city');
        if (!in_array($day_type, ['city','intercity'], true)) $day_type = 'city';

        global $wpdb;
        $daysT = DB::table('tour_days');
        $now = current_time('mysql');

        $max = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(day_index) FROM $daysT WHERE tour_id=%d", $tour_id));
        $day_index = $max ? ($max + 1) : 1;

        // Auto-assign day_date from tour start_date (can be overridden later)
        $toursT = DB::table('tours');
        $start_date = $wpdb->get_var($wpdb->prepare("SELECT start_date FROM $toursT WHERE id=%d", $tour_id));
        $day_date = null;
        if ($start_date) {
            $day_date = date('Y-m-d', strtotime($start_date . ' +' . ($day_index - 1) . ' days'));
        }

        $wpdb->insert($daysT, [
            'tour_id' => $tour_id,
            'day_index' => $day_index,
            'day_type' => $day_type,
            'day_date' => $day_date,
            'date_override' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%s','%s','%d','%s','%s']);

        wp_send_json_success(['day_id' => (int)$wpdb->insert_id, 'day_index' => $day_index]);
    }

    public function day_update(): void {
        self::require_operator_or_admin();
        $day_id = isset($_POST['day_id']) ? absint($_POST['day_id']) : 0;
        if (!$day_id) wp_send_json_error(['message' => __('Missing day id', 'gttom')], 400);

        global $wpdb;
        $daysT = DB::table('tour_days');
        $day = $wpdb->get_row($wpdb->prepare("SELECT * FROM $daysT WHERE id=%d", $day_id), ARRAY_A);
        if (!$day) wp_send_json_error(['message' => __('Day not found', 'gttom')], 404);
        self::assert_tour_owner((int)$day['tour_id']);

        $day_type = sanitize_text_field($_POST['day_type'] ?? $day['day_type']);
        if (!in_array($day_type, ['city','intercity'], true)) $day_type = $day['day_type'];

        // Date override: if day_date posted -> set override=1, else allow clearing override
        $posted_date = sanitize_text_field($_POST['day_date'] ?? '');
        $day_date_sql = null;
        $date_override = (int)($day['date_override'] ?? 0);
        if ($posted_date === '') {
            // keep as-is
            $day_date_sql = $day['day_date'] ?? null;
        } elseif ($posted_date === 'auto') {
            $date_override = 0;
            $day_date_sql = null;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted_date)) {
            $date_override = 1;
            $day_date_sql = $posted_date;
        }

        $city_id = isset($_POST['city_id']) ? absint($_POST['city_id']) : (int)($day['city_id'] ?? 0);
        $from_city_id = isset($_POST['from_city_id']) ? absint($_POST['from_city_id']) : (int)($day['from_city_id'] ?? 0);
        $to_city_id = isset($_POST['to_city_id']) ? absint($_POST['to_city_id']) : (int)($day['to_city_id'] ?? 0);

        $data = [
            'day_type' => $day_type,
            'day_date' => $day_date_sql,
            'date_override' => $date_override,
            'title' => sanitize_text_field($_POST['title'] ?? $day['title']),
            'start_time' => sanitize_text_field($_POST['start_time'] ?? $day['start_time']),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? $day['notes']),
            'city' => sanitize_text_field($_POST['city'] ?? $day['city']),
            'from_city' => sanitize_text_field($_POST['from_city'] ?? $day['from_city']),
            'to_city' => sanitize_text_field($_POST['to_city'] ?? $day['to_city']),
            'city_id' => $city_id ?: null,
            'from_city_id' => $from_city_id ?: null,
            'to_city_id' => $to_city_id ?: null,
            'updated_at' => current_time('mysql'),
        ];

        $wpdb->update($daysT, $data, ['id' => $day_id]);

        // If date is not overridden, recompute from tour start_date
        if ((int)$data['date_override'] === 0) {
            $toursT = DB::table('tours');
            $start_date = $wpdb->get_var($wpdb->prepare("SELECT start_date FROM $toursT WHERE id=%d", (int)$day['tour_id']));
            if ($start_date) {
                self::recalc_day_dates((int)$day['tour_id'], $start_date);
            }
        }
        wp_send_json_success(['updated' => true]);
    }

    public function day_delete(): void {
        self::require_operator_or_admin();
        $day_id = isset($_POST['day_id']) ? absint($_POST['day_id']) : 0;
        if (!$day_id) wp_send_json_error(['message' => __('Missing day id', 'gttom')], 400);

        global $wpdb;
        $daysT = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $day = $wpdb->get_row($wpdb->prepare("SELECT * FROM $daysT WHERE id=%d", $day_id), ARRAY_A);
        if (!$day) wp_send_json_error(['message' => __('Day not found', 'gttom')], 404);
        self::assert_tour_owner((int)$day['tour_id']);

        $tour_id = (int)($day['tour_id'] ?? 0);

        // Delete steps + related supplier rows first
        $step_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $stepsT WHERE day_id=%d", $day_id));
        if (!empty($step_ids)) {
            $in = implode(',', array_map('absint', $step_ids));
            // Delete assigned suppliers rows if present
            if ($in) {
                $wpdb->query("DELETE FROM $ssT WHERE step_id IN ($in)");
            }
        }
        $wpdb->delete($stepsT, ['day_id' => $day_id], ['%d']);
        $wpdb->delete($daysT, ['id' => $day_id], ['%d']);

        // Re-index remaining days so there are no gaps (Day 1, Day 2, ...)
        if ($tour_id) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $daysT WHERE tour_id=%d ORDER BY day_index ASC", $tour_id));
            $i = 1;
            foreach ($ids as $id) {
                $id = absint($id);
                if (!$id) continue;
                $wpdb->update($daysT, ['day_index' => $i, 'updated_at' => current_time('mysql')], ['id' => $id], ['%d','%s'], ['%d']);
                $i++;
            }

            // If tour has a start_date, recompute non-overridden day dates
            $toursT = DB::table('tours');
            $start_date = $wpdb->get_var($wpdb->prepare("SELECT start_date FROM $toursT WHERE id=%d", $tour_id));
            if ($start_date) {
                self::recalc_day_dates($tour_id, $start_date);
            }
        }

        wp_send_json_success(['deleted' => true, 'reindexed' => true]);
    }

    public function day_reorder(): void {
        self::require_operator_or_admin();
        $tour_id = isset($_POST['tour_id']) ? absint($_POST['tour_id']) : 0;
        $order = isset($_POST['order']) ? (array) $_POST['order'] : [];
        if (!$tour_id || !$order) wp_send_json_error(['message' => __('Missing reorder data', 'gttom')], 400);
        self::assert_tour_owner($tour_id);

        global $wpdb;
        $daysT = DB::table('tour_days');
        $i = 1;
        foreach ($order as $day_id) {
            $day_id = absint($day_id);
            if (!$day_id) continue;
            $wpdb->update($daysT, ['day_index' => $i, 'updated_at' => current_time('mysql')], ['id' => $day_id]);
            $i++;
        }
        wp_send_json_success(['reordered' => true]);
    }

    /**
     * ------------------------------------------------------------
     * Phase 2: Steps CRUD
     * ------------------------------------------------------------
     */
    public function step_add(): void {
        self::require_operator_or_admin();
        $day_id  = isset($_POST['day_id']) ? absint($_POST['day_id']) : 0;
        if (!$day_id) wp_send_json_error(['message' => __('Missing day id', 'gttom')], 400);

        global $wpdb;
        $daysT  = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $day = $wpdb->get_row($wpdb->prepare("SELECT * FROM $daysT WHERE id=%d", $day_id), ARRAY_A);
        if (!$day) wp_send_json_error(['message' => __('Day not found', 'gttom')], 404);
        self::assert_tour_owner((int)$day['tour_id']);

        $step_type = sanitize_text_field($_POST['step_type'] ?? 'custom');
        $allowed = ['hotel','pickup','transfer','meal','activity','guide','fee','driver','full_day_car','tour_package','custom'];
        if (!in_array($step_type, $allowed, true)) $step_type = 'custom';

        $title = sanitize_text_field($_POST['title'] ?? '');
        if (!$title) $title = ucfirst(str_replace('_',' ', $step_type));

        $now = current_time('mysql');
        $max = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(step_index) FROM $stepsT WHERE day_id=%d", $day_id));
        $step_index = $max ? ($max + 1) : 1;

        $wpdb->insert($stepsT, [
            'tour_id' => (int)$day['tour_id'],
            'day_id' => $day_id,
            'step_index' => $step_index,
            'step_type' => $step_type,
            'title' => $title,
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'time' => sanitize_text_field($_POST['time'] ?? ''),
            'qty' => isset($_POST['qty']) ? max(1, absint($_POST['qty'])) : 1,
            'price_amount' => null,
            'price_currency' => null,
            'price_overridden' => 0,
            'status' => 'not_booked',
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'supplier_type' => sanitize_text_field($_POST['supplier_type'] ?? ''),
            'supplier_id' => isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null,
            'supplier_snapshot' => sanitize_textarea_field($_POST['supplier_snapshot'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        wp_send_json_success(['step_id' => (int)$wpdb->insert_id, 'step_index' => $step_index]);
    }

    public function step_update(): void {
        self::require_operator_or_admin();
        $step_id  = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        if (!$step_id) wp_send_json_error(['message' => __('Missing step id', 'gttom')], 400);

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT * FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => __('Step not found', 'gttom')], 404);
        self::assert_tour_owner((int)$step['tour_id']);

        $allowed = ['hotel','pickup','transfer','meal','activity','guide','fee','driver','full_day_car','tour_package','custom'];
        $step_type = sanitize_text_field($_POST['step_type'] ?? $step['step_type']);
        if (!in_array($step_type, $allowed, true)) $step_type = $step['step_type'];

        $data = [
            'step_type' => $step_type,
            'title' => sanitize_text_field($_POST['title'] ?? $step['title']),
            'description' => sanitize_textarea_field($_POST['description'] ?? $step['description']),
            'time' => sanitize_text_field($_POST['time'] ?? $step['time']),
            'qty' => isset($_POST['qty']) ? max(1, absint($_POST['qty'])) : (int)$step['qty'],
            'notes' => sanitize_textarea_field($_POST['notes'] ?? $step['notes']),
            'supplier_type' => sanitize_text_field($_POST['supplier_type'] ?? $step['supplier_type']),
            'supplier_id' => isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : (int)$step['supplier_id'],
            'supplier_snapshot' => sanitize_textarea_field($_POST['supplier_snapshot'] ?? $step['supplier_snapshot']),
            'updated_at' => current_time('mysql'),
        ];

        $wpdb->update($stepsT, $data, ['id' => $step_id]);
        wp_send_json_success(['updated' => true]);
    }

    public function step_delete(): void {
        self::require_operator_or_admin();
        $step_id  = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        if (!$step_id) wp_send_json_error(['message' => __('Missing step id', 'gttom')], 400);

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT * FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => __('Step not found', 'gttom')], 404);
        self::assert_tour_owner((int)$step['tour_id']);

        $wpdb->delete($stepsT, ['id' => $step_id], ['%d']);
        wp_send_json_success(['deleted' => true]);
    }

    public function step_reorder(): void {
        self::require_operator_or_admin();
        $day_id = isset($_POST['day_id']) ? absint($_POST['day_id']) : 0;
        $order = isset($_POST['order']) ? (array) $_POST['order'] : [];
        if (!$day_id || !$order) wp_send_json_error(['message' => __('Missing reorder data', 'gttom')], 400);

        global $wpdb;
        $daysT = DB::table('tour_days');
        $day = $wpdb->get_row($wpdb->prepare("SELECT * FROM $daysT WHERE id=%d", $day_id), ARRAY_A);
        if (!$day) wp_send_json_error(['message' => __('Day not found', 'gttom')], 404);
        self::assert_tour_owner((int)$day['tour_id']);

        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $i = 1;
        foreach ($order as $step_id) {
            $step_id = absint($step_id);
            if (!$step_id) continue;
            $wpdb->update($stepsT, ['step_index' => $i, 'updated_at' => current_time('mysql')], ['id' => $step_id]);
            $i++;
        }
        wp_send_json_success(['reordered' => true]);
    }

    /**
     * ------------------------------------------------------------
     * Phase 2.2 – Catalog CRUD (AJAX-only, operator-owned)
     * ------------------------------------------------------------
     */
    private static function catalog_map(): array {
        return [
            'cities' => ['table' => 'cities', 'fields' => ['name', 'country', 'is_active', 'meta_json']],
            'hotels' => ['table' => 'hotels', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            'guides' => ['table' => 'guides', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            // Alias for UI naming
            'activities' => ['table' => 'guides', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            'drivers' => ['table' => 'drivers', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            'meals' => ['table' => 'meals', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            'fees' => ['table' => 'fees', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            'pickups' => ['table' => 'pickups', 'fields' => ['name', 'city_id', 'is_active', 'meta_json']],
            'transfers' => ['table' => 'transfers', 'fields' => ['name', 'from_city_id', 'to_city_id', 'is_active', 'meta_json']],
            'full_day_cars' => ['table' => 'full_day_cars', 'fields' => ['name', 'city_id', 'capacity', 'is_active', 'meta_json']],
            'tour_packages' => ['table' => 'tour_packages', 'fields' => ['name', 'is_active', 'meta_json']],
            'suppliers' => ['table' => 'suppliers', 'fields' => ['name', 'supplier_type', 'city_id', 'phone', 'email', 'is_active', 'meta_json']],
        ];
    }

    private static function sanitize_meta_json($raw): ?string {
        if ($raw === null) return null;
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') return null;
        // accept either JSON string or plain text; if JSON invalid, store as JSON-encoded string
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return wp_json_encode($decoded);
        return wp_json_encode(['text' => $raw]);
    }

    private static function is_catalog_used(string $supplier_type, int $supplier_id): bool {
        global $wpdb;
        $steps = DB::table('tour_steps');
        $cnt = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $steps WHERE supplier_type=%s AND supplier_id=%d",
            $supplier_type,
            $supplier_id
        ));
        return $cnt > 0;
    }

    public function catalog_list(): void {
        self::require_access();

        $entity = sanitize_key($_POST['entity'] ?? '');
        $map = self::catalog_map();
        if (!isset($map[$entity])) wp_send_json_error(['message' => 'Invalid entity'], 400);

        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);
        $company_id = (int) self::current_company_id();

        // If company context is missing, company-shared entities will appear empty.
        // Return a clear error so UI can guide the operator.
        if ($company_id <= 0 && in_array($entity, ['suppliers','cities'], true)) {
            wp_send_json_error([
                'message' => 'Company context is not set for your user. Suppliers and Cities are company-scoped and cannot be loaded.'
            ], 400);
        }

        global $wpdb;
        $table = DB::table($map[$entity]['table']);

        // Phase 6: suppliers and cities are shared within a company.
        if (in_array($entity, ['suppliers','cities'], true)) {
            $opT  = DB::table('operators');
            $cuT  = DB::table('company_users');
            // Use an alias for the entity table to avoid ambiguous column errors.
            $alias = $entity === 'suppliers' ? 's' : 'c';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT $alias.*
                 FROM $table $alias
                 INNER JOIN $opT o ON o.id = $alias.operator_id
                 INNER JOIN $cuT cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status='active'
                 ORDER BY $alias.is_active DESC, $alias.name ASC, $alias.id DESC",
                $company_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE operator_id=%d ORDER BY is_active DESC, name ASC, id DESC",
                $operator_id
            ), ARRAY_A);
        }

        // Normalize names (avoid legacy escaped apostrophes like \' ).
        foreach ($rows as &$r) {
            if (isset($r['name'])) {
                $r['name'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$r['name']));
            }
        }
        unset($r);

        // Enrich cities lookup for display convenience (Catalog forms need the full city list).
        // Phase 6: Cities are shared within a company. Even for operator-scoped catalog entities (hotels/activities/etc),
        // operators must be able to pick any active company city. Otherwise dropdowns appear incomplete.
        if (in_array($entity, ['hotels','guides','activities','drivers','meals','fees','pickups','full_day_cars','transfers','suppliers'], true)) {
            $cities_table = DB::table('cities');
            $cities = [];

            if ($company_id > 0) {
                // Company-scoped active cities.
                $opT  = DB::table('operators');
                $cuT  = DB::table('company_users');
                $cities = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.id, c.name
                     FROM $cities_table c
                     INNER JOIN $opT o ON o.id = c.operator_id
                     INNER JOIN $cuT cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status='active'
                     WHERE c.is_active=1
                     ORDER BY c.name ASC",
                    $company_id
                ), ARRAY_A);
            } else {
                // Fallback (older installs without company context): operator-scoped active cities.
                $cities = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, name FROM $cities_table WHERE operator_id=%d AND is_active=1 ORDER BY name ASC",
                    $operator_id
                ), ARRAY_A);
            }
            foreach ($cities as &$c) {
                if (isset($c['name'])) {
                    $c['name'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$c['name']));
                }
            }
            unset($c);
            wp_send_json_success(['items' => $rows, 'cities' => $cities]);
        }

        wp_send_json_success(['items' => $rows]);
    }

    public function catalog_save(): void {
        self::require_access();

        $entity = sanitize_key($_POST['entity'] ?? '');
        $map = self::catalog_map();
        if (!isset($map[$entity])) wp_send_json_error(['message' => 'Invalid entity'], 400);

        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);
        $company_id = (int) self::current_company_id();

        if ($company_id <= 0 && in_array($entity, ['suppliers','cities'], true)) {
            wp_send_json_error([
                'message' => 'Company context is not set for your user. Suppliers and Cities are company-scoped and cannot be saved.'
            ], 400);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        // Guard against accidental "slashed" apostrophes coming from older UI payloads.
        $name = str_replace(["\\'", "\\\""], ["'", '"'], $name);
        if ($name === '') wp_send_json_error(['message' => 'Name is required'], 400);

        $data = [
            'operator_id' => $operator_id,
            'name' => $name,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%d','%s','%s'];

        // Optional common fields
        if (isset($_POST['is_active'])) {
            $data['is_active'] = (int) ($_POST['is_active'] ? 1 : 0);
            $formats[] = '%d';
        }

        // Entity specific
        if ($entity === 'cities' && isset($_POST['country'])) {
            $data['country'] = sanitize_text_field(wp_unslash($_POST['country']));
            $data['country'] = str_replace(["\\'", "\\\""], ["'", '"'], $data['country']);
            $formats[] = '%s';
        }
        foreach (['city_id','from_city_id','to_city_id'] as $k) {
            if (isset($_POST[$k])) {
                $data[$k] = (int) $_POST[$k] ?: null;
                $formats[] = $data[$k] === null ? '%s' : '%d';
            }
        }
        if (isset($_POST['capacity'])) {
            $cap = (int) $_POST['capacity'];
            $data['capacity'] = $cap > 0 ? $cap : null;
            $formats[] = $data['capacity'] === null ? '%s' : '%d';
        }

        // Supplier fields (Phase 5+): used only for suppliers entity
        if (isset($_POST['supplier_type'])) {
            $st = sanitize_key(wp_unslash($_POST['supplier_type']));
            $allowed_types = ['guide','driver','global'];
            $data['supplier_type'] = in_array($st, $allowed_types, true) ? $st : 'global';
            $formats[] = '%s';
        }
        if (isset($_POST['phone'])) {
            $data['phone'] = sanitize_text_field(wp_unslash($_POST['phone']));
            $formats[] = '%s';
        }
        if (isset($_POST['email'])) {
            $data['email'] = sanitize_email(wp_unslash($_POST['email']));
            $formats[] = '%s';
        }

        if (array_key_exists('meta_json', $_POST)) {
            $data['meta_json'] = self::sanitize_meta_json(wp_unslash($_POST['meta_json']));
            $formats[] = '%s';
        }

        global $wpdb;
        $table = DB::table($map[$entity]['table']);

        if ($id > 0) {
            // Ensure ownership / company access
            $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT operator_id FROM $table WHERE id=%d", $id));
            if (!$owner) wp_send_json_error(['message' => 'Not found'], 404);

            if (in_array($entity, ['suppliers','cities'], true)) {
                // Company-scoped entities: allow edit by any operator within the same company.
                $opT = DB::table('operators');
                $cuT = DB::table('company_users');
                $ok = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(1)
                     FROM $opT o
                     INNER JOIN $cuT cu ON cu.user_id=o.user_id AND cu.company_id=%d AND cu.status='active'
                     WHERE o.id=%d",
                    $company_id,
                    $owner
                ));
                if ($ok < 1) wp_send_json_error(['message' => 'Not found'], 404);
            } else {
                if ($owner !== $operator_id) wp_send_json_error(['message' => 'Not found'], 404);
            }

            unset($data['operator_id']);
            $wpdb->update($table, $data, ['id' => $id]);
            wp_send_json_success(['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $formats[] = '%s';
            if (!isset($data['is_active'])) { $data['is_active'] = 1; $formats[] = '%d'; }
            $wpdb->insert($table, $data);
            wp_send_json_success(['id' => (int) $wpdb->insert_id]);
        }
    }

    public function catalog_toggle(): void {
        self::require_access();

        $entity = sanitize_key($_POST['entity'] ?? '');
        $map = self::catalog_map();
        if (!isset($map[$entity])) wp_send_json_error(['message' => 'Invalid entity'], 400);

        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);
        $company_id = (int) self::current_company_id();

        if ($company_id <= 0 && in_array($entity, ['suppliers','cities'], true)) {
            wp_send_json_error([
                'message' => 'Company context is not set for your user. Suppliers and Cities are company-scoped and cannot be updated.'
            ], 400);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $is_active = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        if (!$id) wp_send_json_error(['message' => 'Invalid id'], 400);

        global $wpdb;
        $table = DB::table($map[$entity]['table']);
        $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT operator_id FROM $table WHERE id=%d", $id));
        if (!$owner) wp_send_json_error(['message' => 'Not found'], 404);
        if (in_array($entity, ['suppliers','cities'], true)) {
            $opT = DB::table('operators');
            $cuT = DB::table('company_users');
            $ok = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM $opT o INNER JOIN $cuT cu ON cu.user_id=o.user_id AND cu.company_id=%d AND cu.status='active' WHERE o.id=%d",
                $company_id,
                $owner
            ));
            if ($ok < 1) wp_send_json_error(['message' => 'Not found'], 404);
        } else {
            if ($owner !== $operator_id) wp_send_json_error(['message' => 'Not found'], 404);
        }

        $wpdb->update($table, ['is_active' => $is_active, 'updated_at' => current_time('mysql')], ['id' => $id], ['%d','%s'], ['%d']);
        wp_send_json_success(['id' => $id, 'is_active' => $is_active]);
    }

    public function catalog_delete(): void {
        self::require_access();

        $entity = sanitize_key($_POST['entity'] ?? '');
        $map = self::catalog_map();
        if (!isset($map[$entity])) wp_send_json_error(['message' => 'Invalid entity'], 400);

        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);
        $company_id = (int) self::current_company_id();

        if ($company_id <= 0 && in_array($entity, ['suppliers','cities'], true)) {
            wp_send_json_error([
                'message' => 'Company context is not set for your user. Suppliers and Cities are company-scoped and cannot be deleted.'
            ], 400);
        }

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => 'Invalid id'], 400);

        global $wpdb;
        $table = DB::table($map[$entity]['table']);

        $row = $wpdb->get_row($wpdb->prepare("SELECT id, operator_id FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$row) wp_send_json_error(['message' => 'Not found'], 404);
        if (in_array($entity, ['suppliers','cities'], true)) {
            $opT = DB::table('operators');
            $cuT = DB::table('company_users');
            $ok = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM $opT o INNER JOIN $cuT cu ON cu.user_id=o.user_id AND cu.company_id=%d AND cu.status='active' WHERE o.id=%d",
                $company_id,
                (int)$row['operator_id']
            ));
            if ($ok < 1) wp_send_json_error(['message' => 'Not found'], 404);
        } else {
            if ((int)$row['operator_id'] !== $operator_id) wp_send_json_error(['message' => 'Not found'], 404);
        }

        // If used by any tour step, soft-disable instead of deleting.
        if (self::is_catalog_used($entity, $id)) {
            $wpdb->update($table, ['is_active' => 0, 'updated_at' => current_time('mysql')], ['id' => $id], ['%d','%s'], ['%d']);
            wp_send_json_success(['deleted' => false, 'disabled' => true]);
        }

        $wpdb->delete($table, ['id' => $id], ['%d']);
        wp_send_json_success(['deleted' => true]);
    }

    /**
     * Phase 2.3: Compact active catalog payload for builder UX (cities + suppliers).
     */
    public function catalog_compact(): void {
        self::require_access();

        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);
        $company_id = (int) self::current_company_id();

        global $wpdb;
        $entities = [
            'cities' => ['table' => 'cities', 'fields' => 'id, name, country'],
            'hotels' => ['table' => 'hotels', 'fields' => 'id, name, city_id'],
            'activities' => ['table' => 'guides', 'fields' => 'id, name, city_id'],
            'transfers' => ['table' => 'transfers', 'fields' => 'id, name, from_city_id, to_city_id'],
            'pickups' => ['table' => 'pickups', 'fields' => 'id, name, city_id'],
            'full_day_cars' => ['table' => 'full_day_cars', 'fields' => 'id, name, city_id, capacity'],
            'meals' => ['table' => 'meals', 'fields' => 'id, name, city_id'],
            'fees' => ['table' => 'fees', 'fields' => 'id, name, city_id'],
            'tour_packages' => ['table' => 'tour_packages', 'fields' => 'id, name'],
            // IMPORTANT: use table alias for supplier fields to avoid ambiguous-column SQL errors
            // when joining company membership tables.
            'suppliers' => ['table' => 'suppliers', 'fields' => 's.id AS id, s.supplier_type, s.name, s.city_id, s.phone, s.email'],
        ];

        $out = [];
        foreach ($entities as $key => $cfg) {
            $table = DB::table($cfg['table']);

            // Phase 6: Suppliers are company-scoped (created by any operator within the company).
            // Cities are also shared within a company because suppliers reference cities.
            // Other catalog entities remain operator-scoped to avoid unintended schema/refactor churn.
            if ($key === 'suppliers') {
                $supT = DB::table('suppliers');
                $opT  = DB::table('operators');
                $cuT  = DB::table('company_users');
                $out[$key] = $wpdb->get_results($wpdb->prepare(
                    "SELECT {$cfg['fields']}, s.is_active
                     FROM $supT s
                     INNER JOIN $opT o ON o.id = s.operator_id
                     INNER JOIN $cuT cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status='active'
                     WHERE s.is_active=1
                     ORDER BY s.name ASC",
                    $company_id
                ), ARRAY_A);
                continue;
            }

            if ($key === 'cities') {
                $cT  = DB::table('cities');
                $opT = DB::table('operators');
                $cuT = DB::table('company_users');
                $out[$key] = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.id, c.name, c.country, c.is_active
                     FROM $cT c
                     INNER JOIN $opT o ON o.id = c.operator_id
                     INNER JOIN $cuT cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status='active'
                     WHERE c.is_active=1
                     ORDER BY c.name ASC",
                    $company_id
                ), ARRAY_A);
                continue;
            }
            $out[$key] = $wpdb->get_results($wpdb->prepare(
                "SELECT {$cfg['fields']}, is_active FROM $table WHERE operator_id=%d AND is_active=1 ORDER BY name ASC",
                $operator_id
            ), ARRAY_A);
        }

        // Normalize names (avoid legacy escaped apostrophes like \' ).
        foreach ($out as &$items) {
            if (!is_array($items)) continue;
            foreach ($items as &$r) {
                if (isset($r['name'])) {
                    $r['name'] = str_replace(["\\'", "\\\""], ["'", '"'], wp_unslash((string)$r['name']));
                }
            }
            unset($r);
        }
        unset($items);

        wp_send_json_success(['catalog' => $out]);
    }

    /**
     * Phase 2.3: Set a step supplier with server-side validation + snapshot.
     */
    public function step_set_supplier(): void {
        self::require_operator_or_admin();

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        $entity  = sanitize_key($_POST['entity'] ?? ''); // e.g. hotels, guides, transfers
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        // Optional note (not enforced here). Some UIs provide a "why changed" message.
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        if (!$step_id) wp_send_json_error(['message' => 'Missing step id'], 400);

        $map = self::catalog_map();
        if ($entity !== '' && !isset($map[$entity])) {
            wp_send_json_error(['message' => 'Invalid supplier entity'], 400);
        }

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT * FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => 'Step not found'], 404);
        self::assert_tour_owner((int)$step['tour_id']);

        // Removing supplier (catalog selection cleared)
        if ($entity === '' || $supplier_id === 0) {
            // Audit trail (minimal)
            $old_name = '';
            if (!empty($step['supplier_snapshot'])) {
                $snap = json_decode((string)$step['supplier_snapshot'], true);
                if (is_array($snap) && !empty($snap['name'])) $old_name = (string)$snap['name'];
            }
            if (!$old_name && !empty($step['supplier_id'])) {
                $mapT = DB::table('suppliers');
                if ($mapT) {
                    $old_name = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM $mapT WHERE id=%d", (int)$step['supplier_id']));
                }
            }

            // Phase 9.2: If this is the legacy single-supplier selector for suppliers,
            // require a reason when removing an existing supplier.
            if ((string)($step['supplier_type'] ?? '') === 'suppliers' && ((int)($step['supplier_id'] ?? 0) > 0) && !$reason) {
                wp_send_json_error(['message' => 'Reason is required to remove a supplier'], 400);
            }

            $wpdb->update($stepsT, [
                'supplier_type' => null,
                'supplier_id' => null,
                'supplier_snapshot' => null,
                'updated_at' => current_time('mysql'),
            ], ['id' => $step_id]);

            $toursT = DB::table('tours');
            $company_id = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $toursT WHERE id=%d", (int)$step['tour_id']));
            if ($company_id <= 0) $company_id = (int) self::current_company_id();
            if (class_exists('GTTOM\\Audit') && (string)($step['supplier_type'] ?? '') === 'suppliers') {
                Audit::log($company_id, (int)$step['tour_id'], $step_id, 'supplier_removed', $old_name ?: 'Supplier', '', [
                    'reason' => $reason,
                    'supplier_id' => (int)($step['supplier_id'] ?? 0),
                ], get_current_user_id());
            }
            wp_send_json_success(['updated' => true, 'removed' => true]);
        }

        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);

        $table_name = (string)($map[$entity]['table'] ?? '');
        $table = DB::table($table_name);

        // Phase 6: Suppliers are company-scoped. In Ops Console, operators must be able to
        // assign suppliers created by any active operator inside the same company.
        if ($table_name === 'suppliers') {
            $toursT = DB::table('tours');
            $company_id = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $toursT WHERE id=%d", (int)$step['tour_id']));
            if ($company_id <= 0) $company_id = (int) self::current_company_id();

            $supT = DB::table('suppliers');
            $opT  = DB::table('operators');
            $cuT  = DB::table('company_users');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*
                 FROM $supT s
                 INNER JOIN $opT o ON o.id = s.operator_id
                 INNER JOIN $cuT cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status='active'
                 WHERE s.id=%d AND s.is_active=1
                 LIMIT 1",
                $company_id,
                $supplier_id
            ), ARRAY_A);
        } else {
            // Other catalog entities remain operator-scoped.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND operator_id=%d", $supplier_id, $operator_id), ARRAY_A);
        }
        if (!$row) wp_send_json_error(['message' => 'Supplier not found'], 404);

        $snapshot = [
            'entity' => $entity,
            'id' => (int)$row['id'],
            'name' => $row['name'] ?? ($row['title'] ?? ''),
        ];
        foreach (['city_id','from_city_id','to_city_id','capacity','meta_json'] as $k) {
            if (isset($row[$k])) $snapshot[$k] = $row[$k];
        }

        // Audit values
        $old_name = '';
        if (!empty($step['supplier_snapshot'])) {
            $snap = json_decode((string)$step['supplier_snapshot'], true);
            if (is_array($snap) && !empty($snap['name'])) $old_name = (string)$snap['name'];
        }

        // Phase 9.2: If this is the legacy single-supplier selector for suppliers,
        // require a reason when changing from one supplier to another.
        if ($entity === 'suppliers' && $old_name !== '' && $old_name !== (string)($snapshot['name'] ?? '') && !$reason) {
            wp_send_json_error(['message' => 'Reason is required to change supplier'], 400);
        }

        $wpdb->update($stepsT, [
            'supplier_type' => $entity,
            'supplier_id' => $supplier_id,
            'supplier_snapshot' => wp_json_encode($snapshot),
            'updated_at' => current_time('mysql'),
        ], ['id' => $step_id]);

        $toursT = DB::table('tours');
        $company_id = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $toursT WHERE id=%d", (int)$step['tour_id']));
        if ($company_id <= 0) $company_id = (int) self::current_company_id();
        $new_name = (string)($snapshot['name'] ?? '');
        if (class_exists('GTTOM\\Audit') && $entity === 'suppliers') {
            $action = $old_name ? 'supplier_changed' : 'supplier_assigned';
            Audit::log($company_id, (int)$step['tour_id'], $step_id, $action, $old_name, $new_name, [
                'supplier_id' => $supplier_id,
                'reason' => $reason,
            ], get_current_user_id());
        }

        wp_send_json_success(['updated' => true]);
    }

    /**
     * Phase 5.1: Add a supplier (from suppliers catalog) to a step.
     * Multi-supplier per step.
     */
    public function step_add_supplier(): void {
        self::require_operator_or_admin();

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        
        // Reason is optional for assignment (Phase 6.2.x). Removal requires a reason.
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        if (!$step_id || !$supplier_id) {
            wp_send_json_error(['message' => 'Missing step_id or supplier_id'], 400);
        }

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT * FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => 'Step not found'], 404);
        $tour = self::assert_tour_owner((int)$step['tour_id']);
        $company_id = (int)($tour['company_id'] ?? self::current_company_id());
        if ($company_id <= 0) {
            wp_send_json_error(['message' => 'Company not found'], 403);
        }

        // Current operator row (used for audit/provisioning only)
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => 'Operator not found'], 403);

        // Phase 6: Suppliers are company-scoped (supplier may be created by any operator in the same company).
        // We validate membership by joining suppliers -> operators.user_id -> company_users.
        $supT = DB::table('suppliers');
        $opsT = DB::table('operators');
        $cuT  = DB::table('company_users');

        $sup = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id, s.name, s.supplier_type, s.phone, s.email
             FROM $supT s
             LEFT JOIN $opsT o ON o.id = s.operator_id
             LEFT JOIN $cuT cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status = 'active'
             WHERE s.id = %d AND s.is_active = 1 AND cu.id IS NOT NULL
             LIMIT 1",
            $company_id,
            $supplier_id
        ), ARRAY_A);
        if (!$sup) wp_send_json_error(['message' => 'Supplier not found for this company'], 404);

        $ssT = DB::table('tour_step_suppliers');
        $now = current_time('mysql');

        // Insert (dedupe via UNIQUE KEY)
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $ssT (operator_id, tour_id, step_id, supplier_id, supplier_type, created_at)
             VALUES (%d, %d, %d, %d, %s, %s)",
            // Store company_id for future-safe scoping. (Column name kept for backwards compatibility.)
            $company_id,
            (int)$step['tour_id'],
            $step_id,
            $supplier_id,
            (string)($sup['supplier_type'] ?? ''),
            $now
        ));

        // Phase 8: Structured audit log (append-only, stored in tour_notes)
        self::syslog_add((int)$step['tour_id'], $step_id, 'supplier_assigned', [
            'supplier_id' => $supplier_id,
            'supplier_name' => (string)($sup['name'] ?? ''),
            'reason' => $reason,
        ]);

        // Phase 9.1: Minimal audit trail (dedicated table)
        if (class_exists('GTTOM\\Audit')) {
            $label = (string)($sup['name'] ?? ('Supplier #' . $supplier_id));
            Audit::log($company_id, (int)$step['tour_id'], $step_id, 'supplier_assigned', '', $label, [
                'supplier_id' => $supplier_id,
                'reason' => $reason,
            ], get_current_user_id());
        }

        wp_send_json_success(['added' => true]);
    }

    /**
     * Phase 5.1: Remove a supplier from a step.
     */
    public function step_remove_supplier(): void {
        self::require_operator_or_admin();

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        if (!$step_id || !$supplier_id) {
            wp_send_json_error(['message' => 'Missing step_id or supplier_id'], 400);
        }
        // Phase 9.2: Removal requires a reason (Ops-only supplier assignment).
        if (!$reason) {
            wp_send_json_error(['message' => 'Reason is required to remove a supplier'], 400);
        }

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT * FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => 'Step not found'], 404);
        self::assert_tour_owner((int)$step['tour_id']);

        // Company-scoped supplier assignment: delete regardless of which operator originally assigned it.
        $ssT = DB::table('tour_step_suppliers');
        $wpdb->delete($ssT, [
            'tour_id' => (int)$step['tour_id'],
            'step_id' => $step_id,
            'supplier_id' => $supplier_id,
        ], ['%d','%d','%d']);

        // Phase 8: Structured audit log (append-only, stored in tour_notes)
        $supT = DB::table('suppliers');
        $supName = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM $supT WHERE id=%d", $supplier_id));
        self::syslog_add((int)$step['tour_id'], $step_id, 'supplier_removed', [
            'supplier_id' => $supplier_id,
            'supplier_name' => $supName,
            'reason' => $reason,
        ]);

        // Phase 9.1: Minimal audit trail (dedicated table)
        $toursT = DB::table('tours');
        $company_id = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $toursT WHERE id=%d", (int)$step['tour_id']));
        if ($company_id <= 0) $company_id = (int) self::current_company_id();
        if (class_exists('GTTOM\\Audit')) {
            Audit::log($company_id, (int)$step['tour_id'], $step_id, 'supplier_removed', ($supName ?: ('Supplier #' . $supplier_id)), '', [
                'supplier_id' => $supplier_id,
                'reason' => $reason,
            ], get_current_user_id());
        }

        // If there is an open supplier request token for this (step, supplier), cancel it so
        // the supplier cannot accept/decline a request that no longer applies.
        $reqT = DB::table('step_supplier_requests');
        if ($reqT) {
            $now = current_time('mysql');
            $wpdb->query($wpdb->prepare(
                "UPDATE $reqT
                 SET response=%s, responded_at=%s
                 WHERE step_id=%d AND supplier_id=%d AND responded_at IS NULL AND response IS NULL",
                'cancelled',
                $now,
                $step_id,
                $supplier_id
            ));
        }

        wp_send_json_success(['removed' => true]);
    }

    /**
     * Fetch suppliers assigned to steps. Returns map: step_id => [ {id,name,phone,supplier_type} ]
     */
    private static function step_suppliers_map(array $step_ids, int $operator_id): array {
        $step_ids = array_values(array_filter(array_map('intval', $step_ids)));
        if (!$step_ids) return [];

        global $wpdb;
        $ssT = DB::table('tour_step_suppliers');
        $supT = DB::table('suppliers');

        // Phase 6: Supplier assignment is company-scoped. The assignment table historically stored
        // an operator_id, but in a multi-operator company we must show assignments regardless of
        // which operator created them. We therefore scope only by the provided step IDs.
        // NOTE: step IDs are derived from an already-authorized tour payload.
        $placeholders = implode(',', array_fill(0, count($step_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT ss.step_id, s.id AS supplier_id, s.name, s.phone, s.supplier_type
             FROM $ssT ss
             LEFT JOIN $supT s ON s.id=ss.supplier_id
             WHERE ss.step_id IN ($placeholders)
             ORDER BY ss.id ASC",
            $step_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $sid = (int)$r['step_id'];
            if (!isset($out[$sid])) $out[$sid] = [];
            $name = isset($r['name']) ? str_replace(["\'", "\""], ["'", '"'], wp_unslash((string)$r['name'])) : '';
            $out[$sid][] = [
                'id' => (int)($r['supplier_id'] ?? 0),
                'name' => $name,
                'phone' => (string)($r['phone'] ?? ''),
                'supplier_type' => (string)($r['supplier_type'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Phase 8: Append-only system log entry (stored in tour_notes as SYSLOG JSON).
     * No DB schema changes.
     */
    private static function syslog_add(int $tour_id, int $step_id, string $event, array $data = []): void {
        if ($tour_id < 1) return;
        global $wpdb;
        $notesT = DB::table('tour_notes');
        $operator_id = self::current_operator_id();
        $author_user_id = get_current_user_id();
        $payload = [
            'v' => 1,
            'event' => $event,
            'tour_id' => $tour_id,
            'step_id' => $step_id,
            'company_id' => self::current_company_id(),
            'operator_id' => (int)$operator_id,
            'author_user_id' => (int)$author_user_id,
            'data' => $data,
        ];
        $note = 'SYSLOG:' . wp_json_encode($payload);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($notesT, [
            'tour_id' => $tour_id,
            'operator_id' => (int)$operator_id,
            'author_user_id' => (int)$author_user_id,
            'note' => $note,
            'created_at' => current_time('mysql'),
        ]);
    }


    /**
     * ------------------------------------------------------------
     * Phase 3 – Execution: Status + Notes (Operator + Agent)
     * ------------------------------------------------------------
     */
    public function step_set_status(): void {
        // Operator or assigned Agent
        check_ajax_referer('gttom_nonce', 'nonce');

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        $to      = sanitize_text_field($_POST['status'] ?? '');
        $allowed = ['not_booked','pending','booked','paid'];
        if (!$step_id || !in_array($to, $allowed, true)) {
            wp_send_json_error(['message' => __('Invalid status data', 'gttom')], 400);
        }

        $is_operator = (current_user_can('gttom_operator_access') || current_user_can('gttom_admin_access'));
        $is_agent    = (current_user_can('gttom_agent_access') || current_user_can('gttom_admin_access'));
        if (!$is_operator && !$is_agent) {
            wp_send_json_error(['message' => __('Access denied', 'gttom')], 403);
        }
        if ($to === 'paid' && !$is_operator) {
            wp_send_json_error(['message' => __('Only operator can mark paid', 'gttom')], 403);
        }

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT id,tour_id,status FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) {
            wp_send_json_error(['message' => __('Step not found', 'gttom')], 404);
        }

        $tour_id = (int)$step['tour_id'];
        if ($is_operator) {
            self::assert_tour_owner($tour_id);
        } else {
            self::assert_agent_assigned($tour_id);
        }

        $from = (string)$step['status'];
        // If the step is already pending and the operator re-selects "Pending",
        // we still need to ensure newly assigned suppliers receive the request.
        if ($from === $to) {
            if ($to === 'pending' && class_exists('GTTOM\\Notifications')) {
                Notifications::ensure_supplier_pending_requests($step_id);
            }
            wp_send_json_success(['updated' => true, 'status' => $to]);
        }

        $now = current_time('mysql');
        $wpdb->update($stepsT, ['status' => $to, 'updated_at' => $now], ['id' => $step_id], ['%s','%s'], ['%d']);

        // Immutable log
        $logT = DB::table('status_log');
        $wpdb->insert($logT, [
            'tour_id' => $tour_id,
            'step_id' => $step_id,
            'changed_by' => get_current_user_id(),
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => $now,
        ], ['%d','%d','%d','%s','%s','%s']);

        // Phase 9.1: Minimal audit trail (dedicated table)
        $toursT = DB::table('tours');
        $company_id = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $toursT WHERE id=%d", $tour_id));
        if ($company_id <= 0) $company_id = (int) self::current_company_id();
        if (class_exists('GTTOM\\Audit')) {
            Audit::log($company_id, $tour_id, $step_id, 'status_changed', $from, $to, [], get_current_user_id());
        }

        // Phase 9.4: optional silent edit (skip notifications)
        $silent = !empty($_POST['silent']);
        if (!$silent && class_exists('GTTOM\\Notifications')) {
            Notifications::on_step_status_changed($step_id, $from, $to);
        }

        wp_send_json_success(['updated' => true, 'status' => $to]);
    }

    public function step_set_notes(): void {
        // Operator or assigned Agent
        check_ajax_referer('gttom_nonce', 'nonce');

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        if (!$step_id) wp_send_json_error(['message' => __('Missing step id', 'gttom')], 400);

        $is_operator = (current_user_can('gttom_operator_access') || current_user_can('gttom_admin_access'));
        $is_agent    = (current_user_can('gttom_agent_access') || current_user_can('gttom_admin_access'));
        if (!$is_operator && !$is_agent) {
            wp_send_json_error(['message' => __('Access denied', 'gttom')], 403);
        }

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $step = $wpdb->get_row($wpdb->prepare("SELECT id,tour_id FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => __('Step not found', 'gttom')], 404);

        $tour_id = (int)$step['tour_id'];
        if ($is_operator) {
            self::assert_tour_owner($tour_id);
        } else {
            self::assert_agent_assigned($tour_id);
        }

        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $wpdb->update($stepsT, ['notes' => $notes, 'updated_at' => current_time('mysql')], ['id' => $step_id]);
        wp_send_json_success(['updated' => true]);
    }

    /**
     * Phase 8: Step activity timeline (status changes, supplier assignments, requests/responses).
     * Lazy-loaded in Ops Console.
     */
    public function step_activity(): void {
        self::require_operator_or_admin();
        check_ajax_referer('gttom_nonce', 'nonce');

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        if (!$step_id) wp_send_json_error(['message' => __('Missing step id', 'gttom')], 400);

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $step = $wpdb->get_row($wpdb->prepare("SELECT id,tour_id,status,title FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => __('Step not found', 'gttom')], 404);

        $tour = self::assert_tour_owner((int)$step['tour_id']);
        $tour_id = (int)$tour['id'];
        $company_id = (int)($tour['company_id'] ?? self::current_company_id());

        $events = [];

        // 1) Status changes (status_log)
        $logT = DB::table('status_log');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT from_status,to_status,changed_by,created_at FROM $logT WHERE step_id=%d ORDER BY created_at DESC LIMIT 60",
            $step_id
        ), ARRAY_A);
        foreach ($rows as $r) {
            $user_id = (int)($r['changed_by'] ?? 0);
            $u = $user_id ? get_userdata($user_id) : null;
            $who = $u ? $u->display_name : ('User ' . $user_id);
            $events[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'type' => 'status',
            ];
        }

        // 2) Supplier assignments (current + syslog removals)
        $ssT = DB::table('tour_step_suppliers');
        $supT = DB::table('suppliers');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ss.supplier_id, ss.created_at, s.name FROM $ssT ss LEFT JOIN $supT s ON s.id=ss.supplier_id WHERE ss.step_id=%d ORDER BY ss.created_at DESC LIMIT 60",
            $step_id
        ), ARRAY_A);
        foreach ($rows as $r) {
            $events[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'type' => 'supplier_assigned',
                'message' => 'Supplier assigned: ' . (string)($r['name'] ?: ('Supplier #' . (int)$r['supplier_id'])),
            ];
        }

        // 3) Requests / responses (step_supplier_requests)
        $reqT = DB::table('step_supplier_requests');
        if ($company_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT supplier_id, token, channel, expires_at, responded_at, response, created_at FROM $reqT WHERE company_id=%d AND step_id=%d ORDER BY created_at DESC LIMIT 80",
                $company_id,
                $step_id
            ), ARRAY_A);
            foreach ($rows as $r) {
                $sid = (int)($r['supplier_id'] ?? 0);
                $name = (string)$wpdb->get_var($wpdb->prepare("SELECT name FROM $supT WHERE id=%d", $sid));
                if (!$name) $name = 'Supplier #' . $sid;
                $ch = (string)($r['channel'] ?? '');
                $events[] = [
                    'ts' => (string)($r['created_at'] ?? ''),
                    'type' => 'request_sent',
                    'message' => sprintf('Request sent to %s via %s', $name, $ch ? strtoupper($ch) : 'channel'),
                ];
                if (!empty($r['response'])) {
                    $events[] = [
                        'ts' => (string)($r['responded_at'] ?? $r['created_at'] ?? ''),
                        'type' => 'response',
                        'message' => sprintf('%s responded: %s', $name, strtoupper((string)$r['response'])),
                    ];
                }
            }
        }

        // 4) System logs (tour_notes SYSLOG)
        $notesT = DB::table('tour_notes');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT note, created_at FROM $notesT WHERE tour_id=%d AND note LIKE %s ORDER BY created_at DESC LIMIT 200",
            $tour_id,
            'SYSLOG:%'
        ), ARRAY_A);
        foreach ($rows as $r) {
            $raw = (string)($r['note'] ?? '');
            $json = substr($raw, 7);
            $p = json_decode($json, true);
            if (!is_array($p)) continue;
            if ((int)($p['step_id'] ?? 0) !== $step_id) continue;
            $ev = (string)($p['event'] ?? '');
            $data = (array)($p['data'] ?? []);
            if ($ev === 'supplier_removed') {
                $events[] = [
                    'ts' => (string)($r['created_at'] ?? ''),
                    'type' => 'supplier_removed',
                    'message' => 'Supplier removed: ' . (string)($data['supplier_name'] ?? ('Supplier #' . (int)($data['supplier_id'] ?? 0))) . (isset($data['reason']) ? (' (Reason: ' . (string)$data['reason'] . ')') : ''),
                ];
            } elseif ($ev === 'supplier_assigned') {
                $events[] = [
                    'ts' => (string)($r['created_at'] ?? ''),
                    'type' => 'supplier_assigned',
                    'message' => 'Supplier assigned: ' . (string)($data['supplier_name'] ?? ('Supplier #' . (int)($data['supplier_id'] ?? 0))),
                ];
            }
        }

        // Sort DESC by timestamp
        usort($events, function($a, $b) {
            return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
        });

        // De-dupe identical message+ts
        $seen = [];
        $out = [];
        foreach ($events as $e) {
            $k = (string)($e['ts'] ?? '') . '|' . (string)($e['message'] ?? '');
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $e;
        }

        wp_send_json_success(['events' => array_slice($out, 0, 120)]);
    }

    /**
     * Phase 9.1: Ops Audit Trail — minimal, read-only.
     * Uses dedicated audit_log table.
     */
    public function step_audit(): void {
        self::require_operator_or_admin();
        check_ajax_referer('gttom_nonce', 'nonce');

        $step_id = isset($_POST['step_id']) ? absint($_POST['step_id']) : 0;
        if (!$step_id) wp_send_json_error(['message' => __('Missing step id', 'gttom')], 400);

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $step = $wpdb->get_row($wpdb->prepare("SELECT id,tour_id FROM $stepsT WHERE id=%d", $step_id), ARRAY_A);
        if (!$step) wp_send_json_error(['message' => __('Step not found', 'gttom')], 404);

        $tour = self::assert_tour_owner((int)$step['tour_id']);
        $company_id = (int)($tour['company_id'] ?? self::current_company_id());
        if ($company_id <= 0) wp_send_json_error(['message' => __('Company not found', 'gttom')], 403);

        $rows = [];
        if (class_exists('GTTOM\\Audit')) {
            $rows = Audit::get_step_events($company_id, $step_id, 80);
        }

        $events = [];
        foreach ($rows as $r) {
            $events[] = [
                'ts' => (string)($r['ts'] ?? ''),
                'message' => class_exists('GTTOM\\Audit') ? Audit::ui_message($r) : '',
            ];
        }

        wp_send_json_success(['events' => $events]);
    }

    /**
     * ------------------------------------------------------------
     * Phase 3 – Agent execution view (assigned tours only)
     * ------------------------------------------------------------
     */
    public function agent_my_tours(): void {
        self::require_agent_or_admin();

        $agent = self::current_agent_row();
        if (!current_user_can('gttom_admin_access') && (!$agent || empty($agent['id']))) {
            wp_send_json_error(['message' => __('Agent not found. Ask operator to add you as an agent.', 'gttom')], 400);
        }

        global $wpdb;
        $toursT = DB::table('tours');
        $taT = DB::table('tour_agents');

        if (current_user_can('gttom_admin_access')) {
            // Admin may view all tours in this phase (debug).
            $rows = $wpdb->get_results("SELECT id,name,start_date,pax,currency,vat_rate,status FROM $toursT ORDER BY id DESC LIMIT 100", ARRAY_A);
            wp_send_json_success(['tours' => $rows]);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.name,t.start_date,t.pax,t.currency,t.vat_rate,t.status
             FROM $taT ta
             INNER JOIN $toursT t ON t.id = ta.tour_id
             WHERE ta.agent_id=%d AND t.company_id=%d
             ORDER BY t.start_date DESC, t.id DESC",
            (int)$agent['id'],
            (int) self::current_company_id()
        ), ARRAY_A);

        wp_send_json_success(['tours' => $rows]);
    }

    public function agent_tour_get(): void {
        self::require_agent_or_admin();
        $tour_id = isset($_POST['tour_id']) ? absint($_POST['tour_id']) : 0;
        if (!$tour_id) wp_send_json_error(['message' => __('Missing tour id', 'gttom')], 400);

        if (!current_user_can('gttom_admin_access')) {
            self::assert_agent_assigned($tour_id);
        }

        global $wpdb;
        $toursT = DB::table('tours');
        $daysT  = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');

        $tour = $wpdb->get_row($wpdb->prepare("SELECT * FROM $toursT WHERE id=%d", $tour_id), ARRAY_A);
        if (!$tour) wp_send_json_error(['message' => __('Tour not found', 'gttom')], 404);

        $days = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $daysT WHERE tour_id=%d ORDER BY day_index ASC",
            $tour_id
        ), ARRAY_A);

        $day_ids = array_map(fn($d) => (int)$d['id'], $days);
        $steps_by_day = [];
        if ($day_ids) {
            $in = implode(',', array_fill(0, count($day_ids), '%d'));
            $sql = $wpdb->prepare("SELECT * FROM $stepsT WHERE day_id IN ($in) ORDER BY day_id ASC, step_index ASC", $day_ids);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            foreach ($rows as $r) {
                $did = (int)$r['day_id'];
                if (!isset($steps_by_day[$did])) $steps_by_day[$did] = [];
                // Agents never see pricing or supplier details
                unset($r['price_amount'], $r['price_currency'], $r['price_overridden']);
                unset($r['supplier_type'], $r['supplier_id'], $r['supplier_snapshot']);
                $steps_by_day[$did][] = $r;
            }
        }

        wp_send_json_success([
            'tour' => $tour,
            'days' => $days,
            'steps_by_day' => $steps_by_day,
        ]);
    }

    /**
     * ------------------------------------------------------------
     * Phase 3 – Minimal Agent management for Operators
     * (create/link WP users as agents + assign to tours)
     * ------------------------------------------------------------
     */
    public function operator_tours_list(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        // Phase 6.1: company-scoped tours (operators in the same company share tours)
        $company_id = self::current_company_id();
        if (!current_user_can('gttom_admin_access') && !$company_id) {
            wp_send_json_error(['message' => __('Company not set. Ask admin to assign you to a company.', 'gttom')], 403);
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $health_sort = sanitize_text_field($_POST['health_sort'] ?? 'critical_first');
        $status = sanitize_text_field($_POST['status'] ?? '');
        $agent_id = absint($_POST['agent_id'] ?? 0);
        $q = sanitize_text_field($_POST['q'] ?? '');

        global $wpdb;
        $toursT = DB::table('tours');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $daysT  = DB::table('tour_days');
        $taT    = DB::table('tour_agents');
        $agentsT= DB::table('agents');

        $where = "t.company_id=%d";
        // By default, hide cancelled (soft-deleted) tours unless explicitly filtered.
        if ($status === '') { $where .= " AND t.status!='cancelled'"; }
        $args = [$company_id ?: 0];

        if ($status && in_array($status, ['draft','in_progress','completed'], true)) {
            $where .= " AND t.status=%s";
            $args[] = $status;
        }

        if ($date_from) { $where .= " AND t.start_date >= %s"; $args[] = $date_from; }
        if ($date_to)   { $where .= " AND t.start_date <= %s"; $args[] = $date_to; }

        if ($q) {
            $where .= " AND (t.name LIKE %s OR CAST(t.id AS CHAR) LIKE %s)";
            $like = '%' . $wpdb->esc_like($q) . '%';
            $args[] = $like;
            $args[] = $like;
        }

        // Agent filter: only tours that have this agent assigned.
        $agentJoin = "";
        if ($agent_id) {
            $agentJoin = " INNER JOIN $taT taf ON taf.tour_id=t.id AND taf.agent_id=" . (int)$agent_id . " ";
        }

        $sql = "
            SELECT
                t.id,
                t.name,
                t.start_date,
                t.pax,
                t.status,
                COALESCE(a.display_name, a.email) AS agent_name,
                a.id AS agent_id,
                COALESCE(SUM(CASE WHEN s.status='not_booked' THEN 1 ELSE 0 END),0) AS unbooked,
                COALESCE(SUM(CASE WHEN s.status='pending' THEN 1 ELSE 0 END),0) AS pending,
                COALESCE(SUM(CASE
                    WHEN s.status IN ('not_booked','pending')
                     AND (
                        COALESCE(d.day_date, DATE_ADD(t.start_date, INTERVAL (d.day_index-1) DAY)) < CURDATE()
                     )
                    THEN 1 ELSE 0 END),0) AS overdue
            FROM $toursT t
            $agentJoin
            LEFT JOIN $stepsT s ON s.tour_id=t.id
            LEFT JOIN $daysT d ON d.id=s.day_id
            LEFT JOIN (
                SELECT ta.tour_id, ag.id, ag.display_name, ag.email
                FROM $taT ta
                INNER JOIN $agentsT ag ON ag.id=ta.agent_id
            ) a ON a.tour_id=t.id
            WHERE $where
            GROUP BY t.id
            ";

        // Ordering
        $order = " ORDER BY t.start_date DESC, t.id DESC ";
        if ($health_sort === 'critical_first') {
            $order = " ORDER BY (overdue*100 + unbooked*10 + pending) DESC, t.start_date ASC ";
        } elseif ($health_sort === 'warning_first') {
            $order = " ORDER BY (CASE WHEN (overdue+unbooked)=0 AND pending>0 THEN 1 ELSE 0 END) DESC, (overdue*100 + unbooked*10 + pending) DESC, t.start_date ASC ";
        } elseif ($health_sort === 'healthy_first') {
            $order = " ORDER BY (CASE WHEN (overdue+unbooked+pending)=0 THEN 1 ELSE 0 END) DESC, t.start_date ASC ";
        } elseif ($health_sort === 'none') {
            $order = " ORDER BY t.start_date DESC, t.id DESC ";
        }

        $sql .= $order . " LIMIT 200";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        // Compute health + label
        foreach ($rows as &$r) {
            $u = (int)$r['unbooked'];
            $p = (int)$r['pending'];
            $o = (int)$r['overdue'];
            $health = 'healthy';
            if ($o > 0 || $u > 0) $health = 'critical';
            else if ($p > 0) $health = 'warning';
            $r['health'] = $health;
        }

        wp_send_json_success(['tours' => $rows]);
    }

    public function operator_tour_health_details(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        $tour_id = absint($_POST['tour_id'] ?? 0);
        if (!$tour_id) wp_send_json_error(['message' => __('Invalid tour', 'gttom')], 400);

        $tour = self::assert_tour_owner($tour_id);

        global $wpdb;
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $daysT  = DB::table('tour_days');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.title, s.step_type, s.status, s.time, s.day_id,
                    d.day_index, d.title AS day_title, d.day_date
             FROM $stepsT s
             LEFT JOIN $daysT d ON d.id=s.day_id
             WHERE s.tour_id=%d
             ORDER BY d.day_index ASC, s.step_index ASC",
            $tour_id
        ), ARRAY_A);

        $start_date = $tour['start_date'] ? $tour['start_date'] : null;
        $today = current_time('Y-m-d');

        $out = [
            'unbooked' => [],
            'pending'  => [],
            'overdue'  => [],
        ];

        foreach ($rows as $r) {
            $status = $r['status'];
            if (!in_array($status, ['not_booked','pending'], true)) continue;

            $expected = null;
            if (!empty($r['day_date'])) {
                $expected = $r['day_date'];
            } elseif ($start_date && isset($r['day_index'])) {
                $expected = date('Y-m-d', strtotime($start_date . ' +' . (max(0, ((int)$r['day_index'])-1)) . ' days'));
            }

            $item = [
                'step_id' => (int)$r['id'],
                'day_index' => (int)($r['day_index'] ?? 0),
                'day_title' => $r['day_title'] ?: ('Day ' . (int)($r['day_index'] ?? 0)),
                'title' => $r['title'],
                'step_type' => $r['step_type'],
                'time' => $r['time'],
                'expected_date' => $expected,
                'status' => $status,
            ];

            if ($status === 'not_booked') $out['unbooked'][] = $item;
            if ($status === 'pending') $out['pending'][] = $item;

            if ($expected && $expected < $today) {
                $out['overdue'][] = $item;
            }
        }

        wp_send_json_success(['details' => $out]);
    }


function operator_agents_list(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        global $wpdb;
        $agentsT = DB::table('agents');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $agentsT WHERE operator_id=%d ORDER BY is_active DESC, id DESC",
            $operator_id
        ), ARRAY_A);

        wp_send_json_success(['agents' => $rows]);
    }

    public function operator_agents_add(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        $email = sanitize_email($_POST['email'] ?? '');
        if (!$email) wp_send_json_error(['message' => __('Agent email required', 'gttom')], 400);

        $user = get_user_by('email', $email);
        if (!$user) wp_send_json_error(['message' => __('No WordPress user with this email', 'gttom')], 404);

        // Ensure role is agent
        if (!in_array('gttom_agent', (array)$user->roles, true) && !current_user_can('gttom_admin_access')) {
            // Operator cannot change roles; tell them to set role in WP Users.
            wp_send_json_error(['message' => __('Set this user role to "TourOps Agent" in WordPress Users first.', 'gttom')], 400);
        }

        global $wpdb;
        $agentsT = DB::table('agents');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $agentsT WHERE user_id=%d", (int)$user->ID), ARRAY_A);
        $now = current_time('mysql');

        if ($existing) {
            // If agent belongs to this operator, just re-activate/update profile.
            if ((int)$existing['operator_id'] !== (int)$operator_id) {
                wp_send_json_error(['message' => __('This agent is already linked to another operator.', 'gttom')], 400);
            }
            $wpdb->update($agentsT, [
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'is_active' => 1,
                'updated_at' => $now,
            ], ['id' => (int)$existing['id']]);
            wp_send_json_success(['agent_id' => (int)$existing['id'], 'updated' => true]);
        }

        $wpdb->insert($agentsT, [
            'operator_id' => $operator_id,
            'user_id' => (int)$user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%s','%s','%d','%s','%s']);

        if (!$wpdb->insert_id) {
            wp_send_json_error(['message' => __('DB insert failed', 'gttom')], 500);
        }
        wp_send_json_success(['agent_id' => (int)$wpdb->insert_id]);
    }

    public function operator_assign_agent(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        $tour_id  = isset($_POST['tour_id']) ? absint($_POST['tour_id']) : 0;
        $agent_id = isset($_POST['agent_id']) ? absint($_POST['agent_id']) : 0;
        if (!$tour_id || !$agent_id) wp_send_json_error(['message' => __('Missing assignment data', 'gttom')], 400);

        // Ensure tour belongs to operator
        self::assert_tour_owner($tour_id);

        // Ensure agent belongs to operator
        global $wpdb;
        $agentsT = DB::table('agents');
        $owner = (int)$wpdb->get_var($wpdb->prepare("SELECT operator_id FROM $agentsT WHERE id=%d", $agent_id));
        if (!$owner || $owner !== (int)$operator_id) {
            wp_send_json_error(['message' => __('Agent not found for this operator', 'gttom')], 404);
        }

        $taT = DB::table('tour_agents');
        $now = current_time('mysql');
        // Insert ignore pattern
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $taT WHERE tour_id=%d AND agent_id=%d", $tour_id, $agent_id));
        if (!$exists) {
            $wpdb->insert($taT, ['tour_id' => $tour_id, 'agent_id' => $agent_id, 'created_at' => $now], ['%d','%d','%s']);
        }
        wp_send_json_success(['assigned' => true]);
    }


    /**
     * Soft delete (cancel) a tour: sets status=cancelled and hides it from default list.
     */
    public function tour_soft_delete(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        self::verify_nonce();
        $tour_id = absint($_POST['tour_id'] ?? 0);
        if (!$tour_id) wp_send_json_error(['message' => __('Missing tour_id', 'gttom')], 400);

        global $wpdb;
        $toursT = DB::table('tours');

        // Phase 6.1: company-scoped ownership (any operator in company may manage the tour)
        self::assert_tour_owner($tour_id);

        $wpdb->update($toursT, [
            'status' => 'cancelled',
            'updated_at' => current_time('mysql')
        ], ['id' => $tour_id]);

        wp_send_json_success(['cancelled' => true]);
    }

    /**
     * Hard delete a tour and all related rows (days, steps, logs, assignments).
     * WARNING: irreversible. Confirmed on UI.
     */
    public function tour_hard_delete(): void {
        self::require_operator_or_admin();
        $operator_id = self::current_operator_id();
        if (!$operator_id) wp_send_json_error(['message' => __('Operator not found', 'gttom')], 400);

        self::verify_nonce();
        $tour_id = absint($_POST['tour_id'] ?? 0);
        if (!$tour_id) wp_send_json_error(['message' => __('Missing tour_id', 'gttom')], 400);

        global $wpdb;
        $toursT = DB::table('tours');
        $daysT  = DB::table('tour_days');
        $stepsT = DB::table('tour_steps');
        $ssT    = DB::table('tour_step_suppliers');
        $logT   = DB::table('status_log');
        $notesT = DB::table('tour_notes');
        $taT    = DB::table('tour_agents');

        // Phase 6.1: company-scoped ownership (any operator in company may delete the tour)
        self::assert_tour_owner($tour_id);

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->delete($logT, ['tour_id' => $tour_id]);
            $wpdb->delete($ssT, ['tour_id' => $tour_id]);
            $wpdb->delete($stepsT, ['tour_id' => $tour_id]);
            $wpdb->delete($daysT, ['tour_id' => $tour_id]);
            $wpdb->delete($taT, ['tour_id' => $tour_id]);
            $wpdb->delete($notesT, ['tour_id' => $tour_id]);
            $wpdb->delete($toursT, ['id' => $tour_id]);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => __('Delete failed', 'gttom')], 500);
        }

        wp_send_json_success(['deleted' => true]);
    }

    /* ============================================================
     * Telegram (Option A)
     * - Settings stored in wp_options
     * - Supplier connect uses supplier.meta_json (no DB schema changes)
     * ============================================================ */

    public function telegram_save_settings(): void {
        self::require_access();
        self::verify_nonce();

        // Token: only update if provided. We never echo it back.
        $token = (string) sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $link  = (string) esc_url_raw(wp_unslash($_POST['link'] ?? ''));

        if ($link === '') {
            $link = 'https://t.me/gtopsmanagerbot';
        }
        update_option('gttom_telegram_bot_link', $link);

        if ($token !== '') {
            update_option('gttom_telegram_bot_token', $token);
        }

        // Webhook secret: generated once.
        $secret = (string) get_option('gttom_telegram_webhook_secret', '');
        if ($secret === '') {
            try {
                $secret = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                $secret = wp_generate_password(32, false, false);
            }
            update_option('gttom_telegram_webhook_secret', $secret);
        }

        wp_send_json_success(['saved' => true]);
    }

    private function telegram_api_post(string $method, array $payload = []): array {
        $token = (string) get_option('gttom_telegram_bot_token', '');
        if ($token === '') {
            return ['ok' => false, 'description' => 'Bot token is not configured'];
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . ltrim($method, '/');
        $res = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode($payload),
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'description' => $res->get_error_message()];
        }
        $body = (string) wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['ok' => false, 'description' => 'Invalid response from Telegram'];
    }

    public function telegram_set_webhook(): void {
        self::require_access();
        self::verify_nonce();

        $token = (string) get_option('gttom_telegram_bot_token', '');
        if ($token === '') {
            wp_send_json_error(['message' => 'Bot token is not configured. Save token first.'], 400);
        }

        $webhook = home_url('/wp-json/gttom/v1/telegram-webhook');
        // Telegram requires HTTPS
        $webhook = preg_replace('~^http://~i', 'https://', $webhook);

        $secret = (string) get_option('gttom_telegram_webhook_secret', '');
        if ($secret === '') {
            // Ensure it exists
            try {
                $secret = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                $secret = wp_generate_password(32, false, false);
            }
            update_option('gttom_telegram_webhook_secret', $secret);
        }

        $resp = $this->telegram_api_post('setWebhook', [
            'url' => $webhook,
            'secret_token' => $secret,
            'allowed_updates' => ['message','callback_query'],
        ]);
        if (empty($resp['ok'])) {
            $msg = (string)($resp['description'] ?? 'Failed to set webhook');
            wp_send_json_error(['message' => $msg], 400);
        }
        wp_send_json_success(['message' => 'Webhook set to: ' . $webhook]);
    }

    public function telegram_webhook_info(): void {
        self::require_access();
        self::verify_nonce();
        $resp = $this->telegram_api_post('getWebhookInfo', []);
        if (empty($resp['ok'])) {
            $msg = (string)($resp['description'] ?? 'Failed to get webhook info');
            wp_send_json_error(['message' => $msg], 400);
        }
        $r = $resp['result'] ?? [];
        $url = is_array($r) ? (string)($r['url'] ?? '') : '';
        $pending = is_array($r) ? (int)($r['pending_update_count'] ?? 0) : 0;
        $last = is_array($r) ? (string)($r['last_error_message'] ?? '') : '';
        $msg = 'URL: ' . ($url ?: '—') . ' | pending: ' . $pending;
        if ($last) $msg .= ' | last error: ' . $last;
        wp_send_json_success(['message' => $msg]);
    }

    private function supplier_company_can_edit(int $supplier_id): array {
        $operator_id = self::current_operator_id();
        if (!$operator_id) return [false, 'Operator not found'];
        $company_id = (int) self::current_company_id();
        if ($company_id <= 0) return [false, 'Company context is missing for your user'];

        global $wpdb;
        $supT = DB::table('suppliers');
        $opT  = DB::table('operators');
        $cuT  = DB::table('company_users');

        $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT operator_id FROM $supT WHERE id=%d", $supplier_id));
        if (!$owner) return [false, 'Supplier not found'];

        $ok = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
             FROM $opT o
             INNER JOIN $cuT cu ON cu.user_id=o.user_id AND cu.company_id=%d AND cu.status='active'
             WHERE o.id=%d",
            $company_id,
            $owner
        ));
        if ($ok < 1) return [false, 'Supplier not found'];
        return [true, ''];
    }

    public function supplier_tg_generate(): void {
        self::require_access();
        self::verify_nonce();

        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        if ($supplier_id <= 0) wp_send_json_error(['message' => 'Invalid supplier'], 400);

        [$ok, $err] = $this->supplier_company_can_edit($supplier_id);
        if (!$ok) wp_send_json_error(['message' => $err], 404);

        $bot_link = (string) get_option('gttom_telegram_bot_link', 'https://t.me/gtopsmanagerbot');
        if ($bot_link === '') $bot_link = 'https://t.me/gtopsmanagerbot';

        // Create a short-lived bind token stored in supplier.meta_json
        try {
            $token = bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            $token = wp_generate_password(24, false, false);
        }
        $expires = time() + 30 * 60; // 30 minutes

        global $wpdb;
        $supT = DB::table('suppliers');
        $row = $wpdb->get_row($wpdb->prepare("SELECT meta_json FROM $supT WHERE id=%d", $supplier_id), ARRAY_A);
        $meta = [];
        if (is_array($row) && !empty($row['meta_json'])) {
            $d = json_decode((string)$row['meta_json'], true);
            if (is_array($d)) $meta = $d;
        }
        $meta['telegram_bind_token'] = $token;
        $meta['telegram_bind_expires'] = $expires;

        $wpdb->update($supT, ['meta_json' => wp_json_encode($meta), 'updated_at' => current_time('mysql')], ['id' => $supplier_id]);

        $deeplink = rtrim($bot_link, '/') . '?start=' . rawurlencode($token);
        $command = '/start ' . $token;

        wp_send_json_success([
            'command' => $command,
            'deeplink' => $deeplink,
            'expires' => $expires,
        ]);
    }

    public function supplier_tg_disconnect(): void {
        self::require_access();
        self::verify_nonce();

        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        if ($supplier_id <= 0) wp_send_json_error(['message' => 'Invalid supplier'], 400);

        [$ok, $err] = $this->supplier_company_can_edit($supplier_id);
        if (!$ok) wp_send_json_error(['message' => $err], 404);

        global $wpdb;
        $supT = DB::table('suppliers');
        $row = $wpdb->get_row($wpdb->prepare("SELECT meta_json FROM $supT WHERE id=%d", $supplier_id), ARRAY_A);
        $meta = [];
        if (is_array($row) && !empty($row['meta_json'])) {
            $d = json_decode((string)$row['meta_json'], true);
            if (is_array($d)) $meta = $d;
        }

        unset($meta['telegram_chat_id'], $meta['telegram_username'], $meta['telegram_bind_token'], $meta['telegram_bind_expires']);
        $wpdb->update($supT, ['meta_json' => wp_json_encode($meta), 'updated_at' => current_time('mysql')], ['id' => $supplier_id]);

        wp_send_json_success(['disconnected' => true]);
    }

}
