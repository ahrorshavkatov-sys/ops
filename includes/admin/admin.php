<?php
namespace GTTOM\Admin;

use GTTOM\DB;

if (!defined('ABSPATH')) exit;

class Admin {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'menus']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function menus(): void {
        if (!current_user_can('gttom_admin_access')) return;

        add_menu_page(
            __('TourOps Manager', 'gttom'),
            __('TourOps Manager', 'gttom'),
            'gttom_admin_access',
            'gttom_admin',
            [$this, 'render_dashboard'],
            'dashicons-admin-site-alt3',
            56
        );

        add_submenu_page('gttom_admin', __('Plans', 'gttom'), __('Plans', 'gttom'), 'gttom_manage_plans', 'gttom_admin_plans', [$this, 'render_plans']);
        add_submenu_page('gttom_admin', __('Companies', 'gttom'), __('Companies', 'gttom'), 'gttom_admin_access', 'gttom_admin_companies', [$this, 'render_companies']);
        add_submenu_page('gttom_admin', __('Operators', 'gttom'), __('Operators', 'gttom'), 'gttom_manage_operators', 'gttom_admin_operators', [$this, 'render_operators']);
        add_submenu_page('gttom_admin', __('Notices', 'gttom'), __('Notices', 'gttom'), 'gttom_send_notices', 'gttom_admin_notices', [$this, 'render_notices']);
        add_submenu_page('gttom_admin', __('Redirects & URLs', 'gttom'), __('Redirects & URLs', 'gttom'), 'gttom_admin_access', 'gttom_admin_frontend_urls', [$this, 'render_frontend_urls']);
    }

    public function register_settings(): void {
        if (!current_user_can('gttom_admin_access')) return;

        register_setting('gttom_settings', 'gttom_operator_dashboard_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => home_url('/operator/'),
        ]);

        register_setting('gttom_settings', 'gttom_agent_dashboard_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => home_url('/agent/'),
        ]);
    }

    public function render_dashboard(): void {
        echo '<div class="wrap"><h1>TourOps Manager — Admin</h1>
        <p>This phase includes: DB tables, roles/caps, frontend dashboards via shortcodes, and AJAX tier pricing demo.</p>
        </div>';
    }

    public function render_plans(): void {
        echo '<div class="wrap"><h1>Plans (placeholder)</h1>
        <p>Table: <code>' . esc_html(DB::table('plans')) . '</code></p></div>';
    }

    public function render_operators(): void {
        global $wpdb;
        $table = DB::table('operators');
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200", ARRAY_A);

        echo '<div class="wrap"><h1>Operators</h1>';
        echo '<p>Operators are created by assigning the WP role <code>gttom_operator</code>. When an operator visits the frontend dashboard (or performs an AJAX action), the plugin auto-provisions their record.</p>';
        echo '<p>Table: <code>' . esc_html($table) . '</code></p>';

        if (empty($rows)) {
            echo '<div class="notice notice-warning"><p>No operator records yet. Assign role <strong>TourOps Operator</strong> to a WP user, then have them open the operator dashboard page once.</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1100px;">
            <thead><tr>
                <th>ID</th>
                <th>WP User</th>
                <th>Email</th>
                <th>Company</th>
                <th>Created</th>
            </tr></thead><tbody>';

        foreach ($rows as $r) {
            $u = get_user_by('id', (int)$r['user_id']);
            $login = $u ? $u->user_login : ('#' . (int)$r['user_id']);
            $email = $u ? $u->user_email : '';
            echo '<tr>';
            echo '<td>' . esc_html((string)$r['id']) . '</td>';
            echo '<td>' . esc_html($login) . '</td>';
            echo '<td>' . esc_html($email) . '</td>';
            echo '<td>' . esc_html((string)($r['company_name'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public function render_notices(): void {
        echo '<div class="wrap"><h1>Notices (placeholder)</h1>
        <p>Next: expiry reminder emails + manual operator notices.</p></div>';
    }

    public function render_frontend_urls(): void {
        ?>
        <div class="wrap">
            <h1>Redirects & URLs</h1>
            <p class="description">
                This section exists to support the <strong>frontend-only</strong> architecture:
                Operators and Agents work on frontend pages (shortcodes) and should not rely on wp-admin screens.
                You can override the default dashboard links ...
            </p>
            <p>Operators/Agents are redirected away from wp-admin to these URLs.</p>

            <form method="post" action="options.php">
                <?php settings_fields('gttom_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gttom_operator_dashboard_url">Operator dashboard URL</label></th>
                        <td><input type="url" id="gttom_operator_dashboard_url" name="gttom_operator_dashboard_url" class="regular-text" value="<?php echo esc_attr(get_option('gttom_operator_dashboard_url', home_url('/operator/'))); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gttom_agent_dashboard_url">Agent dashboard URL</label></th>
                        <td><input type="url" id="gttom_agent_dashboard_url" name="gttom_agent_dashboard_url" class="regular-text" value="<?php echo esc_attr(get_option('gttom_agent_dashboard_url', home_url('/agent/'))); ?>"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Phase 6.2 — Companies Admin UI
     */
    private function companies_base_url(array $args = []): string {
        $base = admin_url('admin.php?page=gttom_admin_companies');
        return add_query_arg($args, $base);
    }

    private function require_admin_or_die(): void {
        if (!current_user_can('gttom_admin_access') && !current_user_can('administrator')) {
            wp_die(__('You do not have permission to access this page.', 'gttom'));
        }
    }

    private function resolve_user_from_input(string $input): ?\WP_User {
        $input = trim($input);
        if ($input === '') return null;

        // Numeric ID
        if (ctype_digit($input)) {
            $u = get_user_by('id', (int)$input);
            return $u ?: null;
        }

        // Email
        if (is_email($input)) {
            $u = get_user_by('email', $input);
            return $u ?: null;
        }

        // Login
        $u = get_user_by('login', $input);
        return $u ?: null;
    }

    private function ensure_wp_role_for_company_role(int $user_id, string $company_role): void {
        // Only adjust roles for non-admin users.
        $u = get_user_by('id', $user_id);
        if (!$u) return;
        if (user_can($user_id, 'administrator') || user_can($user_id, 'gttom_admin_access')) return;

        $company_role = strtolower($company_role);
        if ($company_role === 'agent') {
            $u->set_role(\GTTOM\Capabilities::ROLE_AGENT);
        } elseif ($company_role === 'operator' || $company_role === 'owner') {
            $u->set_role(\GTTOM\Capabilities::ROLE_OPERATOR);
        }
    }

    private function handle_companies_post(): array {
        $this->require_admin_or_die();
        $notice = ['type' => '', 'text' => ''];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return $notice;
        if (empty($_POST['gttom_company_nonce']) || !wp_verify_nonce($_POST['gttom_company_nonce'], 'gttom_company_action')) {
            $notice = ['type' => 'error', 'text' => __('Security check failed. Please try again.', 'gttom')];
            return $notice;
        }

        global $wpdb;
        $companiesT = DB::table('companies');
        $cuT = DB::table('company_users');

        $action = isset($_POST['gttom_company_action']) ? sanitize_text_field((string)$_POST['gttom_company_action']) : '';
        // Phase 6.2.3 — ensure company branding fields persist.
        $logo_url = isset($_POST['logo_url']) ? esc_url_raw((string)$_POST['logo_url']) : '';

        try {
            if ($action === 'create_company') {
                $name = sanitize_text_field((string)($_POST['name'] ?? ''));
                $status = sanitize_text_field((string)($_POST['status'] ?? 'active'));
                $owner_user_id = (int)($_POST['owner_user_id'] ?? get_current_user_id());
                if ($name === '') throw new \Exception(__('Company name is required.', 'gttom'));

                $now = current_time('mysql');
                $wpdb->insert($companiesT, [
                    'name' => $name,
                    'logo_url' => $logo_url,
                    'status' => in_array($status, ['active','suspended'], true) ? $status : 'active',
                    'owner_user_id' => $owner_user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['%s','%s','%s','%d','%s','%s']);

                $company_id = (int)$wpdb->insert_id;

                // Ensure owner membership row
                if ($company_id > 0) {
                    $wpdb->replace($cuT, [
                        'company_id' => $company_id,
                        'user_id' => $owner_user_id,
                        'role' => 'owner',
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], ['%d','%d','%s','%s','%s','%s']);
                    $this->ensure_wp_role_for_company_role($owner_user_id, 'owner');
                }

                $notice = ['type' => 'success', 'text' => __('Company created.', 'gttom')];
            }

            if ($action === 'update_company') {
                $company_id = (int)($_POST['company_id'] ?? 0);
                $name = sanitize_text_field((string)($_POST['name'] ?? ''));
                $status = sanitize_text_field((string)($_POST['status'] ?? 'active'));
                $owner_user_id = (int)($_POST['owner_user_id'] ?? 0);
                if ($company_id < 1) throw new \Exception(__('Invalid company.', 'gttom'));
                if ($name === '') throw new \Exception(__('Company name is required.', 'gttom'));

                $now = current_time('mysql');
                $wpdb->update($companiesT, [
                    'name' => $name,
                    'logo_url' => $logo_url,
                    'status' => in_array($status, ['active','suspended'], true) ? $status : 'active',
                    'owner_user_id' => $owner_user_id,
                    'updated_at' => $now,
                ], ['id' => $company_id], ['%s','%s','%s','%d','%s'], ['%d']);

                // Ensure owner membership row exists
                if ($owner_user_id > 0) {
                    $wpdb->replace($cuT, [
                        'company_id' => $company_id,
                        'user_id' => $owner_user_id,
                        'role' => 'owner',
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], ['%d','%d','%s','%s','%s','%s']);
                    $this->ensure_wp_role_for_company_role($owner_user_id, 'owner');
                }

                $notice = ['type' => 'success', 'text' => __('Company updated.', 'gttom')];
            }

            if ($action === 'add_member') {
                $company_id = (int)($_POST['company_id'] ?? 0);
                $user_input = sanitize_text_field((string)($_POST['user_lookup'] ?? ''));
                $role = sanitize_text_field((string)($_POST['role'] ?? 'operator'));
                $status = sanitize_text_field((string)($_POST['member_status'] ?? 'active'));
                if ($company_id < 1) throw new \Exception(__('Invalid company.', 'gttom'));

                $u = $this->resolve_user_from_input($user_input);
                if (!$u) throw new \Exception(__('User not found. Use email, username, or user ID.', 'gttom'));

                $role = in_array($role, ['owner','operator','agent'], true) ? $role : 'operator';
                $status = in_array($status, ['active','inactive'], true) ? $status : 'active';
                $now = current_time('mysql');

                $wpdb->replace($cuT, [
                    'company_id' => $company_id,
                    'user_id' => (int)$u->ID,
                    'role' => $role,
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['%d','%d','%s','%s','%s','%s']);

                $this->ensure_wp_role_for_company_role((int)$u->ID, $role);

                $notice = ['type' => 'success', 'text' => __('Member added/updated.', 'gttom')];
            }

            if ($action === 'update_member') {
                $membership_id = (int)($_POST['membership_id'] ?? 0);
                $role = sanitize_text_field((string)($_POST['role'] ?? 'operator'));
                $status = sanitize_text_field((string)($_POST['member_status'] ?? 'active'));
                if ($membership_id < 1) throw new \Exception(__('Invalid member.', 'gttom'));
                $role = in_array($role, ['owner','operator','agent'], true) ? $role : 'operator';
                $status = in_array($status, ['active','inactive'], true) ? $status : 'active';
                $now = current_time('mysql');

                $wpdb->update($cuT, [
                    'role' => $role,
                    'status' => $status,
                    'updated_at' => $now,
                ], ['id' => $membership_id], ['%s','%s','%s'], ['%d']);

                // Update WP role if we can resolve user_id
                $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $cuT WHERE id=%d", $membership_id));
                if ($uid > 0) $this->ensure_wp_role_for_company_role($uid, $role);

                $notice = ['type' => 'success', 'text' => __('Member updated.', 'gttom')];
            }

            if ($action === 'remove_member') {
                $membership_id = (int)($_POST['membership_id'] ?? 0);
                if ($membership_id < 1) throw new \Exception(__('Invalid member.', 'gttom'));
                $wpdb->delete($cuT, ['id' => $membership_id], ['%d']);
                $notice = ['type' => 'success', 'text' => __('Member removed.', 'gttom')];
            }
        } catch (\Exception $e) {
            $notice = ['type' => 'error', 'text' => $e->getMessage()];
        }

        return $notice;
    }

    public function render_companies(): void {
        $this->require_admin_or_die();

        $notice = $this->handle_companies_post();

        global $wpdb;
        $companiesT = DB::table('companies');
        $cuT = DB::table('company_users');

        $view = isset($_GET['view']) ? sanitize_text_field((string)$_GET['view']) : 'list';
        $company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

        echo '<div class="wrap"><h1>' . esc_html__('Companies', 'gttom') . '</h1>';

        if (!empty($notice['type']) && !empty($notice['text'])) {
            $class = $notice['type'] === 'success' ? 'notice notice-success' : 'notice notice-error';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        // Tabs
        $tabs = [
            'list' => __('Companies List', 'gttom'),
            'new'  => __('Add New', 'gttom'),
        ];
        $active = array_key_exists($view, $tabs) ? $view : 'list';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            $url = $this->companies_base_url(['view' => $k]);
            $cls = 'nav-tab' . ($active === $k ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        if ($company_id > 0 && in_array($view, ['edit','members'], true)) {
            $editUrl = $this->companies_base_url(['view' => 'edit', 'company_id' => $company_id]);
            $memUrl  = $this->companies_base_url(['view' => 'members', 'company_id' => $company_id]);
            echo '<a class="nav-tab ' . esc_attr($view==='edit'?'nav-tab-active':'') . '" href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'gttom') . '</a>';
            echo '<a class="nav-tab ' . esc_attr($view==='members'?'nav-tab-active':'') . '" href="' . esc_url($memUrl) . '">' . esc_html__('Members', 'gttom') . '</a>';
        }
        echo '</h2>';

        if ($view === 'new') {
            $current = wp_get_current_user();
            echo '<h2>' . esc_html__('Create Company', 'gttom') . '</h2>';
            echo '<form method="post">';
            wp_nonce_field('gttom_company_action', 'gttom_company_nonce');
            echo '<input type="hidden" name="gttom_company_action" value="create_company" />';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label>' . esc_html__('Company Name', 'gttom') . '</label></th><td><input type="text" name="name" class="regular-text" required /></td></tr>';
            echo '<tr><th><label>' . esc_html__('Logo URL', 'gttom') . '</label></th><td><input type="url" name="logo_url" class="regular-text" placeholder="https://..." /></td></tr>';
            echo '<tr><th><label>' . esc_html__('Status', 'gttom') . '</label></th><td><select name="status"><option value="active">' . esc_html__('Active', 'gttom') . '</option><option value="suspended">' . esc_html__('Suspended', 'gttom') . '</option></select></td></tr>';
            echo '<tr><th><label>' . esc_html__('Owner WP User ID', 'gttom') . '</label></th><td><input type="number" name="owner_user_id" value="' . esc_attr((string)$current->ID) . '" class="small-text" /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Create Company', 'gttom'));
            echo '</form></div>';
            return;
        }

        if ($view === 'edit' && $company_id > 0) {
            $company = $wpdb->get_row($wpdb->prepare("SELECT * FROM $companiesT WHERE id=%d", $company_id), ARRAY_A);
            if (!$company) {
                echo '<p>' . esc_html__('Company not found.', 'gttom') . '</p></div>';
                return;
            }
            echo '<h2>' . esc_html__('Edit Company', 'gttom') . '</h2>';
            echo '<form method="post">';
            wp_nonce_field('gttom_company_action', 'gttom_company_nonce');
            echo '<input type="hidden" name="gttom_company_action" value="update_company" />';
            echo '<input type="hidden" name="company_id" value="' . esc_attr((string)$company_id) . '" />';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label>' . esc_html__('Company Name', 'gttom') . '</label></th><td><input type="text" name="name" class="regular-text" value="' . esc_attr($company['name']) . '" required /></td></tr>';
            echo '<tr><th><label>' . esc_html__('Logo URL', 'gttom') . '</label></th><td><input type="url" name="logo_url" class="regular-text" value="' . esc_attr($company['logo_url'] ?? '') . '" placeholder="https://..." /></td></tr>';
            echo '<tr><th><label>' . esc_html__('Status', 'gttom') . '</label></th><td><select name="status">';
            $st = $company['status'];
            echo '<option value="active"' . selected($st, 'active', false) . '>' . esc_html__('Active', 'gttom') . '</option>';
            echo '<option value="suspended"' . selected($st, 'suspended', false) . '>' . esc_html__('Suspended', 'gttom') . '</option>';
            echo '</select></td></tr>';
            echo '<tr><th><label>' . esc_html__('Owner WP User ID', 'gttom') . '</label></th><td><input type="number" name="owner_user_id" class="small-text" value="' . esc_attr((string)$company['owner_user_id']) . '" /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Save Company', 'gttom'));
            echo '</form>';

            echo '<p><a class="button button-secondary" href="' . esc_url($this->companies_base_url(['view'=>'members','company_id'=>$company_id])) . '">' . esc_html__('Manage Members', 'gttom') . '</a></p>';
            echo '</div>';
            return;
        }

        if ($view === 'members' && $company_id > 0) {
            $company = $wpdb->get_row($wpdb->prepare("SELECT * FROM $companiesT WHERE id=%d", $company_id), ARRAY_A);
            if (!$company) {
                echo '<p>' . esc_html__('Company not found.', 'gttom') . '</p></div>';
                return;
            }

            echo '<h2>' . sprintf(esc_html__('Members — %s', 'gttom'), esc_html($company['name'])) . '</h2>';

            // Add member form
            echo '<h3>' . esc_html__('Add Member', 'gttom') . '</h3>';
            echo '<form method="post" style="max-width: 900px;">';
            wp_nonce_field('gttom_company_action', 'gttom_company_nonce');
            echo '<input type="hidden" name="gttom_company_action" value="add_member" />';
            echo '<input type="hidden" name="company_id" value="' . esc_attr((string)$company_id) . '" />';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label>' . esc_html__('User (email / username / ID)', 'gttom') . '</label></th><td><input type="text" name="user_lookup" class="regular-text" required /></td></tr>';
            echo '<tr><th><label>' . esc_html__('Company Role', 'gttom') . '</label></th><td><select name="role"><option value="operator">' . esc_html__('Operator', 'gttom') . '</option><option value="agent">' . esc_html__('Agent', 'gttom') . '</option><option value="owner">' . esc_html__('Owner', 'gttom') . '</option></select></td></tr>';
            echo '<tr><th><label>' . esc_html__('Status', 'gttom') . '</label></th><td><select name="member_status"><option value="active">' . esc_html__('Active', 'gttom') . '</option><option value="inactive">' . esc_html__('Inactive', 'gttom') . '</option></select></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Add / Update Member', 'gttom'));
            echo '</form>';

            // Members table
            $members = $wpdb->get_results($wpdb->prepare("SELECT * FROM $cuT WHERE company_id=%d ORDER BY role DESC, id DESC", $company_id), ARRAY_A);

            echo '<h3 style="margin-top:24px;">' . esc_html__('Current Members', 'gttom') . '</h3>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('ID', 'gttom') . '</th>';
            echo '<th>' . esc_html__('WP User', 'gttom') . '</th>';
            echo '<th>' . esc_html__('Email', 'gttom') . '</th>';
            echo '<th>' . esc_html__('Role', 'gttom') . '</th>';
            echo '<th>' . esc_html__('Status', 'gttom') . '</th>';
            echo '<th>' . esc_html__('Actions', 'gttom') . '</th>';
            echo '</tr></thead><tbody>';

            if (!$members) {
                echo '<tr><td colspan="6">' . esc_html__('No members yet.', 'gttom') . '</td></tr>';
            } else {
                foreach ($members as $m) {
                    $u = get_user_by('id', (int)$m['user_id']);
                    $login = $u ? $u->user_login : ('#' . (int)$m['user_id']);
                    $email = $u ? $u->user_email : '';
                    echo '<tr>';
                    echo '<td>' . esc_html((string)$m['id']) . '</td>';
                    echo '<td>' . esc_html($login) . '</td>';
                    echo '<td>' . esc_html($email) . '</td>';
                    echo '<td>' . esc_html(ucfirst((string)$m['role'])) . '</td>';
                    echo '<td>' . esc_html(ucfirst((string)$m['status'])) . '</td>';
                    echo '<td>';

                    // Update role/status inline
                    echo '<form method="post" style="display:inline-block; margin-right:8px;">';
                    wp_nonce_field('gttom_company_action', 'gttom_company_nonce');
                    echo '<input type="hidden" name="gttom_company_action" value="update_member" />';
                    echo '<input type="hidden" name="membership_id" value="' . esc_attr((string)$m['id']) . '" />';
                    echo '<select name="role" style="margin-right:6px;">';
                    foreach (['owner'=>'Owner','operator'=>'Operator','agent'=>'Agent'] as $rk=>$rl) {
                        echo '<option value="' . esc_attr($rk) . '"' . selected($m['role'], $rk, false) . '>' . esc_html($rl) . '</option>';
                    }
                    echo '</select>';
                    echo '<select name="member_status" style="margin-right:6px;">';
                    echo '<option value="active"' . selected($m['status'], 'active', false) . '>' . esc_html__('Active','gttom') . '</option>';
                    echo '<option value="inactive"' . selected($m['status'], 'inactive', false) . '>' . esc_html__('Inactive','gttom') . '</option>';
                    echo '</select>';
                    echo '<button class="button button-small">' . esc_html__('Update', 'gttom') . '</button>';
                    echo '</form>';

                    // Remove
                    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Remove this member from the company?\');">';
                    wp_nonce_field('gttom_company_action', 'gttom_company_nonce');
                    echo '<input type="hidden" name="gttom_company_action" value="remove_member" />';
                    echo '<input type="hidden" name="membership_id" value="' . esc_attr((string)$m['id']) . '" />';
                    echo '<button class="button button-small button-link-delete">' . esc_html__('Remove', 'gttom') . '</button>';
                    echo '</form>';

                    echo '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '</div>';
            return;
        }

        // Default: list
        $companies = $wpdb->get_results("SELECT c.*, 
            (SELECT COUNT(*) FROM $cuT cu WHERE cu.company_id=c.id) AS members_count
            FROM $companiesT c ORDER BY c.id DESC", ARRAY_A);

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('ID', 'gttom') . '</th>';
        echo '<th>' . esc_html__('Name', 'gttom') . '</th>';
        echo '<th>' . esc_html__('Status', 'gttom') . '</th>';
        echo '<th>' . esc_html__('Owner', 'gttom') . '</th>';
        echo '<th>' . esc_html__('Members', 'gttom') . '</th>';
        echo '<th>' . esc_html__('Actions', 'gttom') . '</th>';
        echo '</tr></thead><tbody>';

        if (!$companies) {
            echo '<tr><td colspan="6">' . esc_html__('No companies found.', 'gttom') . '</td></tr>';
        } else {
            foreach ($companies as $c) {
                $owner = get_user_by('id', (int)$c['owner_user_id']);
                $owner_name = $owner ? $owner->user_login : ('#' . (int)$c['owner_user_id']);
                $editUrl = $this->companies_base_url(['view'=>'edit','company_id'=>(int)$c['id']]);
                $memUrl  = $this->companies_base_url(['view'=>'members','company_id'=>(int)$c['id']]);
                echo '<tr>';
                echo '<td>' . esc_html((string)$c['id']) . '</td>';
                echo '<td><strong>' . esc_html($c['name']) . '</strong></td>';
                echo '<td>' . esc_html(ucfirst((string)$c['status'])) . '</td>';
                echo '<td>' . esc_html($owner_name) . '</td>';
                echo '<td>' . esc_html((string)$c['members_count']) . '</td>';
                echo '<td>';
                echo '<a class="button button-small" href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'gttom') . '</a> ';
                echo '<a class="button button-small" href="' . esc_url($memUrl) . '">' . esc_html__('Members', 'gttom') . '</a>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:14px;"><a class="button button-primary" href="' . esc_url($this->companies_base_url(['view'=>'new'])) . '">' . esc_html__('Add New Company', 'gttom') . '</a></p>';

        echo '</div>';
    }


}