<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

/**
 * Phase 6.4.1 (Email) + Phase 6.4.2 (Telegram): Supplier booking requests.
 *
 * Trigger: step status -> pending
 * Action: create (or reuse) a request token (12h expiry) and send to suppliers.
 * Channels:
 *  - Email: Accept/Decline links
 *  - Telegram: Inline buttons via webhook (supplier can link their Telegram by sending /start <token>)
 */
final class Notifications {

    public static function init(): void {
        // Email response links (works for logged-in and logged-out suppliers).
        add_action('admin_post_nopriv_gttom_supplier_respond', [__CLASS__, 'handle_supplier_respond']);
        add_action('admin_post_gttom_supplier_respond', [__CLASS__, 'handle_supplier_respond']);

        // Telegram webhook (REST).
        add_action('rest_api_init', [__CLASS__, 'register_telegram_routes']);

        // Ensure reliable sender identity for wp_mail(). Some SMTP/API transports ignore
        // custom "From:" headers, so we also provide canonical filters.
        add_filter('wp_mail_from', function ($from) {
            $v = Notifications::mail_from_email();
            return $v ? $v : $from;
        }, 20);
        add_filter('wp_mail_from_name', function ($name) {
            $v = Notifications::mail_from_name();
            return $v ? $v : $name;
        }, 20);
    }

    /**
     * Telegram bot token.
     * IMPORTANT: stored server-side (wp_options), never exposed to frontend.
     */
    private static function telegram_bot_token(): string {
        $v = (string) get_option('gttom_telegram_bot_token', '');
        $v = trim($v);

        // SECURITY: Never ship a real token inside plugin code.
        // If not configured, return empty string and Telegram will be treated as disabled.
        return $v;
    }

    /**
     * Public-facing Telegram bot link (used in emails for one-click connection).
     * You can override via wp_option: gttom_telegram_bot_link
     */
    private static function telegram_bot_link(): string {
        $v = (string) get_option('gttom_telegram_bot_link', '');
        $v = trim($v);
        if (!$v) {
            // Default bot link.
            $v = 'https://t.me/gtopsmanagerbot';
        }
        // Basic safety: ensure it starts with https://t.me/
        if (strpos($v, 'https://t.me/') !== 0) {
            $v = 'https://t.me/gtopsmanagerbot';
        }
        return $v;
    }

    private static function telegram_deeplink(string $token): string {
        $base = self::telegram_bot_link();
        $token = trim($token);
        return $token ? ($base . '?start=' . rawurlencode($token)) : $base;
    }

    /**
     * Called when a step status is changed.
     */
    public static function on_step_status_changed(int $step_id, string $from, string $to): void {
        if ($step_id <= 0) return;
        // Supplier requests are keyed per (step_id, supplier_id). Even if status didn't
        // change (e.g., step already pending and a new supplier gets assigned), we still
        // want to ensure each assigned supplier has a request token created and notified.
        if ($to === 'pending') {
            self::send_supplier_pending_requests($step_id);
            return;
        }

        // Phase 10.2: auto notify suppliers when status becomes booked/paid.
        if (in_array($to, ['booked','paid'], true) && $from !== $to) {
            self::send_supplier_status_update($step_id, $to);
            return;
        }

        if ($from === $to) return;
    }

    /**
     * Phase 10.2 — Template-based supplier updates on Booked/Paid.
     * Uses the supplier contact fields + telegram_chat_id stored in supplier meta_json.
     */
    private static function send_supplier_status_update(int $step_id, string $to_status): void {
        if ($step_id <= 0) return;
        global $wpdb;

        $stepsT = DB::table('tour_steps');
        $toursT = DB::table('tours');
        $daysT  = DB::table('tour_days');
        $ssT    = DB::table('tour_step_suppliers');
        $supT   = DB::table('suppliers');

        $step = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id,s.tour_id,s.day_id,s.title,s.step_type,s.status,t.company_id,t.name AS tour_name,d.day_date
             FROM $stepsT s
             INNER JOIN $toursT t ON t.id=s.tour_id
             INNER JOIN $daysT d ON d.id=s.day_id
             WHERE s.id=%d LIMIT 1",
            $step_id
        ), ARRAY_A);
        if (!$step) return;

        $company_id = (int)($step['company_id'] ?? 0);
        if ($company_id < 1) return;

        // Phase 10.2.1 — operator-visible toggles (company-scoped)
        // If disabled, do not send any supplier status updates.
        $auto_enabled = (int) get_option('gttom_p10_automation_enabled_' . $company_id, 1);
        if (!$auto_enabled) return;
        if ($to_status === 'booked') {
            $notify_booked = (int) get_option('gttom_p10_notify_booked_' . $company_id, 1);
            if (!$notify_booked) return;
        }
        if ($to_status === 'paid') {
            $notify_paid = (int) get_option('gttom_p10_notify_paid_' . $company_id, 1);
            if (!$notify_paid) return;
        }

        $templates = class_exists('GTTOM\\Phase10') ? \GTTOM\Phase10::get_templates($company_id) : [];
        $ctx_base = [
            'tour_name' => (string)($step['tour_name'] ?? ''),
            'step_title' => (string)($step['title'] ?? ''),
            'day_date' => (string)($step['day_date'] ?? ''),
        ];

        // Fetch assigned suppliers
        $suppliers = $wpdb->get_results($wpdb->prepare(
            "SELECT sp.id, sp.name, sp.email, sp.meta_json
             FROM $ssT ss
             INNER JOIN $supT sp ON sp.id=ss.supplier_id
             WHERE ss.step_id=%d",
            $step_id
        ), ARRAY_A);
        if (!$suppliers) return;

        foreach ($suppliers as $sup) {
            $supplier_name = (string)($sup['name'] ?? 'Supplier');
            $ctx = array_merge($ctx_base, ['supplier_name' => $supplier_name]);

            if ($to_status === 'booked') {
                $subject = \GTTOM\Phase10::render_template((string)($templates['booked_email_subject'] ?? ''), $ctx);
                $body    = \GTTOM\Phase10::render_template((string)($templates['booked_email_body'] ?? ''), $ctx);
                $tg      = \GTTOM\Phase10::render_template((string)($templates['booked_telegram_body'] ?? ''), $ctx);
            } else {
                $subject = \GTTOM\Phase10::render_template((string)($templates['paid_email_subject'] ?? ''), $ctx);
                $body    = \GTTOM\Phase10::render_template((string)($templates['paid_email_body'] ?? ''), $ctx);
                $tg      = \GTTOM\Phase10::render_template((string)($templates['paid_telegram_body'] ?? ''), $ctx);
            }

            // Email
            $email = sanitize_email((string)($sup['email'] ?? ''));
            if ($email && $subject && $body) {
                wp_mail($email, $subject, $body);
            }

            // Telegram (if connected)
            $meta = [];
            $mj = (string)($sup['meta_json'] ?? '');
            if ($mj) {
                $decoded = json_decode($mj, true);
                if (is_array($decoded)) $meta = $decoded;
            }
            $chat_id = (string)($meta['telegram_chat_id'] ?? '');
            if ($chat_id && $tg) {
                self::telegram_send_message($chat_id, $tg);
            }
        }
    }

    /**
     * Public safe entrypoint for ensuring supplier pending requests exist.
     * This is used when a step is already in "pending" but the operator assigns
     * suppliers after the fact.
     */
    public static function ensure_supplier_pending_requests(int $step_id): void {
        if ($step_id <= 0) return;
        self::send_supplier_pending_requests($step_id);
    }

    private static function mail_from_name(): string {
        $v = (string) get_option('gttom_mail_from_name', 'GT Ops Management');
        return $v ?: 'GT Ops Management';
    }

    private static function mail_from_email(): string {
        $v = (string) get_option('gttom_mail_from_email', 'no-reply@2uzbekistan.com');
        $v = sanitize_email($v);
        return $v ?: 'no-reply@2uzbekistan.com';
    }

    /**
     * Register Telegram webhook endpoint.
     *
     * You must set webhook in Telegram to:
     *   https://yourdomain.com/wp-json/gttom/v1/telegram-webhook
     */
    public static function register_telegram_routes(): void {
        register_rest_route('gttom/v1', '/telegram-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_telegram_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Telegram webhook handler.
     * Supports:
     *  - /start <token> (links supplier chat to the supplier of that request token)
     *  - callback_query: accept/decline buttons
     */
    public static function handle_telegram_webhook(\WP_REST_Request $request) {
        $token = self::telegram_bot_token();
        if (!$token) {
            return new \WP_REST_Response(['ok' => true], 200);
        }

        // Optional security: verify secret token header (configured when setting webhook via Settings).
        $secret = (string) get_option('gttom_telegram_webhook_secret', '');
        if ($secret !== '') {
            $hdr = (string)($request->get_header('x-telegram-bot-api-secret-token') ?? '');
            if ($hdr === '' && !empty($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])) {
                $hdr = (string) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'];
            }
            if (!hash_equals($secret, $hdr)) {
                return new \WP_REST_Response(['ok' => true], 403);
            }
        }

        $update = $request->get_json_params();
        if (!is_array($update)) {
            return new \WP_REST_Response(['ok' => true], 200);
        }

        // Handle /start messages.
        if (!empty($update['message']) && is_array($update['message'])) {
            $msg = $update['message'];
            $text = (string)($msg['text'] ?? '');
            $chat_id = (string)($msg['chat']['id'] ?? '');

            if ($chat_id) {
                $t = trim($text);

                // Support 3 input styles:
                //  1) /start <token>   (preferred; deep-link does this automatically)
                //  2) /start           then supplier pastes token as the next message
                //  3) <token>          token pasted without /start
                $req_token = '';
                if (preg_match('~^/start(?:\s+(.+))?$~i', $t, $m)) {
                    $req_token = trim((string)($m[1] ?? ''));
                    // Telegram deep links can send "link_<token>" depending on bot settings.
                    if (preg_match('~^link[_-]([A-Za-z0-9]{16,64})$~', $req_token, $mm)) {
                        $req_token = $mm[1];
                    }
                    if ($req_token === '') {
                        self::telegram_send_message($chat_id, "To connect, please paste the request code from your email like this:\n\n/start <CODE>\n\nOr simply paste the code directly.");
                        return new \WP_REST_Response(['ok' => true], 200);
                    }
                } elseif (preg_match('~^[A-Za-z0-9]{16,64}$~', $t)) {
                    $req_token = $t;
                }

                if ($req_token) {
                    // First try supplier bind tokens (generated in Catalog → Suppliers → Telegram).
                    $linked = self::link_supplier_telegram_by_bind_token($req_token, $chat_id, $msg);
                    if (!$linked) {
                        // Fallback: request tokens (generated on step Pending; legacy flow)
                        $linked = self::link_supplier_telegram_by_request_token($req_token, $chat_id, $msg);
                    }
                    $reply = $linked
                        ? "✅ Telegram connected. You can now accept/decline requests here."
                        : "❌ Unable to connect. The code is invalid or expired.";
                    self::telegram_send_message($chat_id, $reply);
                }
            }

            return new \WP_REST_Response(['ok' => true], 200);
        }

        // Handle inline button callbacks.
        if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
            $cq = $update['callback_query'];
            $data = (string)($cq['data'] ?? '');
            $chat_id = (string)($cq['message']['chat']['id'] ?? '');
            $callback_id = (string)($cq['id'] ?? '');

            // data format: gttom|accept|TOKEN  or gttom|decline|TOKEN
            if ($chat_id && preg_match('~^gttom\|(accept|decline)\|([A-Za-z0-9]{16,64})$~', $data, $m)) {
                $action = $m[1];
                $req_token = $m[2];

                $result = self::handle_supplier_response_token($req_token, $action === 'accept' ? 'accepted' : 'declined', 'telegram', $chat_id);
                $text = $result['ok']
                    ? ($action === 'accept' ? '✅ Accepted.' : '❌ Declined.')
                    : ('⚠️ ' . ($result['message'] ?? 'Request failed.'));

                // Acknowledge button press.
                if ($callback_id) {
                    self::telegram_answer_callback_query($callback_id, $text);
                }
                // Also send a message into chat for clarity.
                self::telegram_send_message($chat_id, $text);
            }

            return new \WP_REST_Response(['ok' => true], 200);
        }

        return new \WP_REST_Response(['ok' => true], 200);
    }

    private static function telegram_api_post(string $method, array $payload): array {
        $token = self::telegram_bot_token();
        if (!$token) return ['ok' => false, 'error' => 'missing_token'];

        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;
        $res = wp_remote_post($url, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($res)) {
            return ['ok' => false, 'error' => $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'http_' . $code, 'body' => $body];
        }
        return ['ok' => true, 'body' => $body];
    }

    private static function telegram_send_message(string $chat_id, string $text, array $reply_markup = null): void {
        if (!$chat_id) return;
        $payload = [
            'chat_id' => $chat_id,
            'text'    => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($reply_markup) {
            $payload['reply_markup'] = $reply_markup;
        }
        self::telegram_api_post('sendMessage', $payload);
    }

    private static function telegram_answer_callback_query(string $callback_id, string $text): void {
        if (!$callback_id) return;
        self::telegram_api_post('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text' => $text,
            'show_alert' => false,
        ]);
    }

    private static function send_supplier_pending_telegram(string $chat_id, array $d): void {
        $company = (string)($d['company_name'] ?? '');
        $tour    = (string)($d['tour_name'] ?? '');
        $step    = (string)($d['step_title'] ?? '');
        $date    = (string)($d['day_date'] ?? '');
        $desc    = (string)($d['description'] ?? '');
        $tok     = (string)($d['request_token'] ?? '');

        // Keep Telegram message short; description included but truncated.
        $desc = trim(wp_strip_all_tags($desc));
        if (strlen($desc) > 900) {
            $desc = substr($desc, 0, 900) . "...";
        }

        $lines = [];
        $lines[] = "🧭 <b>GT Ops Management</b>";
        $lines[] = "";
        $lines[] = "<b>New service request</b>";
        if ($company) $lines[] = "Company: " . esc_html($company);
        if ($tour)    $lines[] = "Tour: " . esc_html($tour);
        if ($step)    $lines[] = "Step: " . esc_html($step);
        if ($date)    $lines[] = "Date: " . esc_html($date);
        $lines[] = "";
        if ($desc) {
            $lines[] = "<b>Description</b>:";
            $lines[] = esc_html($desc);
            $lines[] = "";
        }
        $lines[] = "⏳ Please respond within 12 hours.";

        $text = implode("\n", $lines);

        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Accept',  'callback_data' => 'gttom|accept|' . $tok],
                    ['text' => '❌ Decline', 'callback_data' => 'gttom|decline|' . $tok],
                ]
            ]
        ];

        self::telegram_send_message($chat_id, $text, $reply_markup);
    }

    private static function supplier_meta_get(int $supplier_id): array {
        global $wpdb;
        $supT = DB::table('suppliers');
        $json = (string) $wpdb->get_var($wpdb->prepare("SELECT meta_json FROM $supT WHERE id=%d LIMIT 1", $supplier_id));
        if (!$json) return [];
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    private static function supplier_meta_set(int $supplier_id, array $meta): void {
        global $wpdb;
        $supT = DB::table('suppliers');
        $wpdb->update($supT, [
            'meta_json' => wp_json_encode($meta),
            'updated_at' => current_time('mysql'),
        ], ['id' => $supplier_id], ['%s','%s'], ['%d']);
    }

    private static function supplier_get_telegram_chat_id(int $supplier_id): string {
        $m = self::supplier_meta_get($supplier_id);
        $cid = (string)($m['telegram_chat_id'] ?? '');
        return $cid;
    }

    /**
     * Option A: Supplier bind tokens (Catalog → Suppliers → Telegram → Connect).
     * Token & expiry live in suppliers.meta_json:
     *  - telegram_bind_token (string)
     *  - telegram_bind_expires (unix timestamp)
     * On successful link, we set:
     *  - telegram_chat_id (string)
     *  - telegram_username (string)
     */
    private static function link_supplier_telegram_by_bind_token(string $bind_token, string $chat_id, array $message): bool {
        global $wpdb;
        $supT = DB::table('suppliers');

        $bind_token = preg_replace('~[^A-Za-z0-9]~', '', $bind_token);
        if ($bind_token === '' || strlen($bind_token) < 16) return false;

        // Prevent the same chat from being linked to multiple suppliers.
        $likeChat = '%"telegram_chat_id":"' . $wpdb->esc_like($chat_id) . '"%';
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $supT WHERE meta_json LIKE %s LIMIT 1", $likeChat));
        if ($existing) {
            // Already linked somewhere. Don't relink silently.
            return false;
        }

        $likeTok = '%"telegram_bind_token":"' . $wpdb->esc_like($bind_token) . '"%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, meta_json FROM $supT WHERE meta_json LIKE %s LIMIT 5", $likeTok), ARRAY_A);
        if (!$rows) return false;

        foreach ($rows as $r) {
            $sid = (int)($r['id'] ?? 0);
            if ($sid <= 0) continue;
            $meta = [];
            if (!empty($r['meta_json'])) {
                $d = json_decode((string)$r['meta_json'], true);
                if (is_array($d)) $meta = $d;
            }
            if (empty($meta['telegram_bind_token']) || (string)$meta['telegram_bind_token'] !== $bind_token) {
                continue;
            }
            $expires = (int)($meta['telegram_bind_expires'] ?? 0);
            if ($expires > 0 && time() > $expires) {
                // Expired bind code
                return false;
            }

            $username = '';
            if (!empty($message['from']['username'])) {
                $username = (string) $message['from']['username'];
            }
            $meta['telegram_chat_id'] = $chat_id;
            if ($username) $meta['telegram_username'] = $username;
            unset($meta['telegram_bind_token'], $meta['telegram_bind_expires']);

            $wpdb->update($supT, [
                'meta_json' => wp_json_encode($meta),
                'updated_at' => current_time('mysql'),
            ], ['id' => $sid]);

            return true;
        }
        return false;
    }

    private static function link_supplier_telegram_by_request_token(string $req_token, string $chat_id, array $message): bool {
        global $wpdb;
        $reqT = DB::table('step_supplier_requests');
        $row = $wpdb->get_row($wpdb->prepare("SELECT supplier_id, expires_at, responded_at, response FROM $reqT WHERE token=%s LIMIT 1", $req_token), ARRAY_A);
        if (!$row) return false;
        $exp_ts = strtotime((string)$row['expires_at'] . ' UTC');
        if (!$exp_ts || $exp_ts < current_time('timestamp', true)) return false;

        $supplier_id = (int)$row['supplier_id'];
        if ($supplier_id < 1) return false;

        $meta = self::supplier_meta_get($supplier_id);
        $meta['telegram_chat_id'] = $chat_id;
        if (!empty($message['from']) && is_array($message['from'])) {
            $meta['telegram_user_id'] = (string)($message['from']['id'] ?? '');
            $meta['telegram_username'] = (string)($message['from']['username'] ?? '');
        }
        self::supplier_meta_set($supplier_id, $meta);
        return true;
    }

    /**
     * Create (or reuse) an open request for each assigned supplier and send email.
     */
    private static function send_supplier_pending_requests(int $step_id): void {
        global $wpdb;

        $stepsT   = DB::table('tour_steps');
        $daysT    = DB::table('tour_days');
        $toursT   = DB::table('tours');
        $compT    = DB::table('companies');
        $ssT      = DB::table('tour_step_suppliers');
        $supT     = DB::table('suppliers');
        $reqT     = DB::table('step_supplier_requests');

        $step = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id,s.tour_id,s.day_id,s.title,s.description,s.status,t.company_id,t.name AS tour_name, d.day_date, c.name AS company_name\n             FROM $stepsT s\n             INNER JOIN $toursT t ON t.id = s.tour_id\n             LEFT JOIN $daysT d ON d.id = s.day_id\n             LEFT JOIN $compT c ON c.id = t.company_id\n             WHERE s.id=%d\n             LIMIT 1",
            $step_id
        ), ARRAY_A);

        if (!$step) return;
        if ((string)$step['status'] !== 'pending') return;

        $company_id = (int)($step['company_id'] ?? 0);
        if ($company_id <= 0) return;

        // Fetch assigned suppliers (company-scoped: suppliers created by any operator in same company).
        $supplier_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sup.id, sup.name, sup.email\n             FROM $ssT ss\n             INNER JOIN $supT sup ON sup.id = ss.supplier_id\n             INNER JOIN " . DB::table('operators') . " o ON o.id = sup.operator_id\n             INNER JOIN " . DB::table('company_users') . " cu ON cu.user_id = o.user_id AND cu.company_id = %d AND cu.status='active'\n             WHERE ss.step_id = %d\n             AND sup.is_active=1\n             ORDER BY sup.name ASC",
            $company_id,
            $step_id
        ), ARRAY_A);

        if (empty($supplier_rows)) return;

        $tour_name    = (string)($step['tour_name'] ?? '');
        $company_name = (string)($step['company_name'] ?? '');
        $step_title   = (string)($step['title'] ?? '');
        $step_desc    = (string)($step['description'] ?? '');
        $day_date     = !empty($step['day_date']) ? (string)$step['day_date'] : '';

        $expires_ts = current_time('timestamp', true) + 12 * HOUR_IN_SECONDS;
        $expires_at = gmdate('Y-m-d H:i:s', $expires_ts);

        foreach ($supplier_rows as $sup) {
            $supplier_id = (int)($sup['id'] ?? 0);
            $email = sanitize_email((string)($sup['email'] ?? ''));
            if ($supplier_id <= 0 || !$email) {
                continue;
            }

            // Reuse an existing open request if still valid.
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, token, expires_at\n                 FROM $reqT\n                 WHERE company_id=%d AND step_id=%d AND supplier_id=%d AND response IS NULL AND responded_at IS NULL\n                 ORDER BY id DESC LIMIT 1",
                $company_id,
                $step_id,
                $supplier_id
            ), ARRAY_A);

            $token = '';
            $existing_valid = false;
            if ($existing) {
                $ex = strtotime((string)$existing['expires_at'] . ' UTC');
                if ($ex && $ex > current_time('timestamp', true)) {
                    $token = (string)$existing['token'];
                    $existing_valid = true;
                }
            }

            if (!$token) {
                $token = bin2hex(random_bytes(16)); // 32 chars
                $wpdb->insert($reqT, [
                    'company_id'   => $company_id,
                    'step_id'      => $step_id,
                    'supplier_id'  => $supplier_id,
                    'token'        => $token,
                    'channel'      => 'email',
                    'expires_at'   => $expires_at,
                    'created_at'   => current_time('mysql'),
                ], ['%d','%d','%d','%s','%s','%s','%s']);
                $existing_valid = false;
            }

            // If a valid request already exists for this (step, supplier), do not send another
            // message to avoid repeated emails/telegrams when a user re-selects "Pending".
            // New suppliers assigned to an already-pending step will not have an existing row
            // and will receive a fresh request.
            if ($existing_valid) {
                continue;
            }

            $accept_url  = self::response_url($token, 'accept');
            $decline_url = self::response_url($token, 'decline');

            $subject = sprintf('%s: Please confirm "%s"', $company_name ?: 'GT Ops', $step_title);

            $html = self::render_supplier_request_email([
                'company_name' => $company_name,
                'tour_name'    => $tour_name,
                'step_title'   => $step_title,
                'day_date'     => $day_date,
                'description'  => $step_desc,
                'accept_url'   => $accept_url,
                'decline_url'  => $decline_url,
                'request_token'=> $token,
            ]);

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                // Reply-To is helpful for suppliers who reply directly from mail clients.
                'Reply-To: ' . self::mail_from_name() . ' <' . self::mail_from_email() . '>',
            ];

            // phpcs:ignore WordPress.Functions.DiscouragedFunction
            $sent = wp_mail($email, $subject, $html, $headers);

            // Some hosts are strict about headers; if sending failed, retry once with only
            // a minimal header set (filters above still enforce From).
            if (!$sent) {
                $sent = wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
            }

            // Telegram: if supplier has linked Telegram, send the same request with inline buttons.
            $chat_id = self::supplier_get_telegram_chat_id($supplier_id);
            if ($chat_id) {
                self::send_supplier_pending_telegram($chat_id, [
                    'company_name' => $company_name,
                    'tour_name'    => $tour_name,
                    'step_title'   => $step_title,
                    'day_date'     => $day_date,
                    'description'  => $step_desc,
                    'request_token'=> $token,
                ]);
            }
        }
    }

    private static function response_url(string $token, string $decision): string {
        $decision = ($decision === 'accept') ? 'accept' : 'decline';
        return add_query_arg([
            'action'   => 'gttom_supplier_respond',
            'token'    => rawurlencode($token),
            'decision' => $decision,
        ], admin_url('admin-post.php'));
    }

    private static function esc_nl2br(string $text): string {
        return nl2br(esc_html($text));
    }

    private static function render_supplier_request_email(array $d): string {
        $company = esc_html((string)($d['company_name'] ?? ''));
        $tour    = esc_html((string)($d['tour_name'] ?? ''));
        $step    = esc_html((string)($d['step_title'] ?? ''));
        $date    = esc_html((string)($d['day_date'] ?? ''));
        $desc    = self::esc_nl2br((string)($d['description'] ?? ''));
        $accept  = esc_url((string)($d['accept_url'] ?? ''));
        $decline = esc_url((string)($d['decline_url'] ?? ''));
        $reqtok_raw = (string)($d['request_token'] ?? '');
        $reqtok  = esc_html($reqtok_raw);
        $tg_link = esc_url(self::telegram_deeplink($reqtok_raw));

        $date_line = $date ? "<p style=\"margin:0 0 10px\"><strong>Date:</strong> $date</p>" : '';

        return "
<div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; line-height:1.45; color:#111;\">
  <h2 style=\"margin:0 0 12px; font-size:18px;\">Service request</h2>
  <p style=\"margin:0 0 10px\"><strong>$company</strong> has assigned you a step.</p>
  <p style=\"margin:0 0 10px\"><strong>Tour:</strong> $tour</p>
  <p style=\"margin:0 0 10px\"><strong>Step:</strong> $step</p>
  $date_line
  <p style=\"margin:0 0 14px\">You have <strong>12 hours</strong> to accept this request.</p>

  <div style=\"margin:14px 0; padding:12px; border:1px solid #eee; border-radius:12px; background:#fafafa\">
    <div style=\"font-size:13px; color:#444; margin-bottom:6px\"><strong>Description</strong></div>
    <div style=\"font-size:14px; color:#111\">$desc</div>
  </div>

  <div style=\"margin:16px 0;\">
    <a href=\"$accept\" style=\"display:inline-block; padding:10px 14px; border-radius:10px; text-decoration:none; background:#16a34a; color:#fff; font-weight:600; margin-right:8px;\">Accept</a>
    <a href=\"$decline\" style=\"display:inline-block; padding:10px 14px; border-radius:10px; text-decoration:none; background:#dc2626; color:#fff; font-weight:600;\">Decline</a>
  </div>

  <div style=\"margin:12px 0 0; padding:10px; border:1px dashed #e5e7eb; border-radius:12px; background:#fff\">
    <div style=\"font-size:12px; color:#444; margin-bottom:6px\"><strong>Telegram option</strong></div>
    <div style=\"font-size:12px; color:#444\">For 1-click Telegram response (and future notifications), tap:</div>
    <div style=\"margin-top:10px\">
      <a href=\"$tg_link\" style=\"display:inline-block; padding:10px 14px; border-radius:10px; text-decoration:none; background:#0ea5e9; color:#fff; font-weight:600\">Open Telegram bot</a>
    </div>
    <div style=\"margin-top:10px; font-size:12px; color:#444\">If the button doesn’t work, open our bot and send:</div>
    <div style=\"margin-top:6px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px;\">/start $reqtok</div>
  </div>

  <p style=\"margin:14px 0 0; font-size:12px; color:#666\">If you did not expect this request, you can ignore this email.</p>
</div>
";
    }

    /**
     * Handle Accept/Decline click from email.
     */
    public static function handle_supplier_respond(): void {
        $token = sanitize_text_field($_GET['token'] ?? '');
        $decision = sanitize_text_field($_GET['decision'] ?? '');
        $decision = ($decision === 'accept') ? 'accepted' : 'declined';

        if (!$token) {
            wp_die('Missing token.', 'GT TourOps', 400);
        }

        $r = self::handle_supplier_response_token($token, $decision, 'email', '');
        if (!$r['ok']) {
            $code = (int)($r['code'] ?? 400);
            wp_die((string)($r['message'] ?? 'Request failed.'), 'GT TourOps', $code);
        }

        if (!empty($r['message'])) {
            wp_die((string)$r['message'], 'GT TourOps', 200);
        }
        $msg = ($decision === 'accepted') ? '✅ Thank you! You have accepted the request.' : '❌ The request was declined.';
        wp_die($msg, 'GT TourOps', 200);
    }

    /**
     * Core accept/decline handler shared by Email and Telegram.
     *
     * @param string $token request token
     * @param string $decision accepted|declined
     * @param string $channel email|telegram
     * @param string $telegram_chat_id for telegram channel validation
     * @return array {ok: bool, code?: int, message?: string}
     */
    private static function handle_supplier_response_token(string $token, string $decision, string $channel, string $telegram_chat_id): array {
        $decision = ($decision === 'accepted') ? 'accepted' : 'declined';
        $channel = ($channel === 'telegram') ? 'telegram' : 'email';

        global $wpdb;
        $reqT   = DB::table('step_supplier_requests');
        $stepsT = DB::table('tour_steps');
        $toursT = DB::table('tours');
        $logT   = DB::table('status_log');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $reqT WHERE token=%s LIMIT 1",
            $token
        ), ARRAY_A);

        if (!$row) {
            return ['ok' => false, 'code' => 404, 'message' => 'Invalid or expired request token.'];
        }

        // Check expiry and one-time use.
        $now_ts = current_time('timestamp', true);
        $exp_ts = strtotime((string)$row['expires_at'] . ' UTC');
        if (!$exp_ts || $exp_ts < $now_ts) {
            return ['ok' => false, 'code' => 410, 'message' => 'This request has expired.'];
        }
        if (!empty($row['responded_at']) || !empty($row['response'])) {
            return ['ok' => false, 'code' => 409, 'message' => 'This request has already been answered.'];
        }

        $supplier_id = (int)$row['supplier_id'];
        if ($channel === 'telegram') {
            if (!$telegram_chat_id) {
                return ['ok' => false, 'code' => 403, 'message' => 'Telegram chat not provided.'];
            }
            $bound = self::supplier_get_telegram_chat_id($supplier_id);
            if (!$bound || $bound !== $telegram_chat_id) {
                return ['ok' => false, 'code' => 403, 'message' => 'This Telegram is not linked to the supplier.'];
            }
        }

        $step_id = (int)$row['step_id'];
        $company_id = (int)$row['company_id'];

        $step = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id,s.tour_id,s.status,t.company_id FROM $stepsT s INNER JOIN $toursT t ON t.id=s.tour_id WHERE s.id=%d LIMIT 1",
            $step_id
        ), ARRAY_A);

        if (!$step || (int)$step['company_id'] !== $company_id) {
            return ['ok' => false, 'code' => 404, 'message' => 'Step not found.'];
        }

        $from = (string)$step['status'];

        // Record answer even if step moved on (no longer pending).
        if ($from !== 'pending') {
            $wpdb->update($reqT, [
                'responded_at' => current_time('mysql'),
                'response'     => $decision,
                'channel'      => $channel,
            ], ['id' => (int)$row['id']], ['%s','%s','%s'], ['%d']);

            return ['ok' => true, 'code' => 200, 'message' => 'This step is no longer pending. Your response was recorded.'];
        }

        $to = ($decision === 'accepted') ? 'booked' : 'not_booked';
        $now_mysql = current_time('mysql');

        $wpdb->update($stepsT, ['status' => $to, 'updated_at' => $now_mysql], ['id' => $step_id], ['%s','%s'], ['%d']);

        // Log as system.
        $wpdb->insert($logT, [
            'tour_id' => (int)$step['tour_id'],
            'step_id' => $step_id,
            'changed_by' => 0,
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => $now_mysql,
        ], ['%d','%d','%d','%s','%s','%s']);

        $wpdb->update($reqT, [
            'responded_at' => $now_mysql,
            'response'     => $decision,
            'channel'      => $channel,
        ], ['id' => (int)$row['id']], ['%s','%s','%s'], ['%d']);

        return ['ok' => true, 'code' => 200];
    }
}
