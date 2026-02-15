<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

function denied(): string {
    return '<div class="gttom-denied">Access denied</div>';
}

function require_operator(): bool {
    return current_user_can('gttom_operator_access') || current_user_can('gttom_admin_access');
}

function require_agent(): bool {
    return current_user_can('gttom_agent_access') || current_user_can('gttom_admin_access');
}

/**
 * ----------------------------------------------------
 * Operator UI (Option A): separate pages + AJAX inside
 * ----------------------------------------------------
 * These helpers render a consistent professional shell
 * (left navigation, header, content area) without
 * changing any business logic.
 */

function operator_url(string $key): string {
    // Defaults are safe. Admin can override in wp-admin → Redirects & URLs.
    $defaults = [
        'overview' => home_url('/operator/overview/'),
        'build'    => home_url('/operator/build-tour/'),
        'assign'   => home_url('/operator/supplier-assignment/'),
        'catalog'  => home_url('/operator/catalog/'),
        'tours'    => home_url('/operator/tours/'),
        'agents'   => home_url('/operator/agents/'),
        'settings' => home_url('/operator/settings/'),
        'timeline' => home_url('/operator/timeline/'),
        'automation' => home_url('/operator/automation/'),
    ];
    $opt = (array) get_option('gttom_frontend_urls', []);
    $val = isset($opt[$key]) && is_string($opt[$key]) && $opt[$key] !== '' ? $opt[$key] : ($defaults[$key] ?? home_url('/'));

    // Self-heal: if a link points to a non-existent page (common on fresh installs),
    // fall back to the operator dashboard URL with a section query param.
    if (function_exists('url_to_postid')) {
        $pid = url_to_postid($val);
        if (!$pid) {
            $dash = (string) get_option('gttom_operator_dashboard_url', home_url('/operator/'));
            $dash = $dash ?: home_url('/operator/');
            $sep = (strpos($dash, '?') === false) ? '?' : '&';
            $val = $dash . $sep . 'section=' . rawurlencode($key);
        }
    }

    return esc_url($val);
}

function svg_icon(string $name): string {
    // Minimal inline icons (no external libraries).
    $icons = [
        'overview' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h7V4H4v9zm0 7h7v-5H4v5zm9 0h7V11h-7v9zm0-18v7h7V4h-7z"/></svg>',
        'build'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h12v2H3v-2z"/></svg>',
        'assign'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H4v6h6V4zm10 0h-6v6h6V4zM10 14H4v6h6v-6zm4 0v6h6v-6h-6z"/></svg>',
        'catalog'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h18v4H3V4zm0 6h18v10H3V10zm4 3v2h10v-2H7z"/></svg>',
        'tours'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l4 8H8l4-8zm-6 9h12v11H6V11zm3 3v2h6v-2H9z"/></svg>',
        'timeline' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v2H5V3zm0 16h14v2H5v-2zM7 7h2v10H7V7zm4 3h2v7h-2v-7zm4-2h2v9h-2V8z"/></svg>',
        'automation' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a5 5 0 0 1 5 5v2h2a3 3 0 0 1 0 6h-2v2a5 5 0 0 1-10 0v-2H5a3 3 0 0 1 0-6h2V7a5 5 0 0 1 5-5zm3 7V7a3 3 0 0 0-6 0v2h6zm0 6H9v2a3 3 0 0 0 6 0v-2z"/></svg>',
        'agents'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 3-1.34 3-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5C23 14.17 18.33 13 16 13z"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.11-.2-.35-.28-.56-.2l-2.39.96c-.5-.38-1.04-.69-1.63-.92l-.36-2.54A.488.488 0 0 0 13.95 1h-3.9c-.24 0-.44.17-.48.41l-.36 2.54c-.59.23-1.13.54-1.63.92l-2.39-.96c-.21-.08-.45 0-.56.2L2.71 7.43c-.11.2-.06.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.5.5 0 0 0-.12.61l1.92 3.32c.11.2.35.28.56.2l2.39-.96c.5.38 1.04.69 1.63.92l.36 2.54c.04.24.24.41.48.41h3.9c.24 0 .44-.17.48-.41l.36-2.54c.59-.23 1.13-.54 1.63-.92l2.39.96c.21.08.45 0 .56-.2l1.92-3.32a.5.5 0 0 0-.12-.61l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function operator_shell(string $active, string $page_title, string $inner_html): string {
    $nav = [
        'overview' => ['label' => 'Overview', 'url' => operator_url('overview')],
        'build'    => ['label' => 'Build Tour', 'url' => operator_url('build')],
        'catalog'  => ['label' => 'Catalog', 'url' => operator_url('catalog')],
        'tours'    => ['label' => 'Tours', 'url' => operator_url('tours')],
        'timeline' => ['label' => 'Timeline', 'url' => operator_url('timeline')],
        'automation' => ['label' => 'Automation', 'url' => operator_url('automation')],
        'agents'   => ['label' => 'Agents', 'url' => operator_url('agents')],
        'settings' => ['label' => 'Settings', 'url' => operator_url('settings')],
    ];

    $user = wp_get_current_user();
    $who = $user && $user->exists() ? esc_html($user->display_name ?: $user->user_login) : '';

    ob_start();
    ?>
    <div class="gttom-pro" data-active="<?php echo esc_attr($active); ?>">
        <aside class="gttom-pro__sidebar" aria-label="Operator navigation">
            <div class="gttom-pro__brand">
                <?php $company = \GTTOM\DB::current_company(); $cName = $company && !empty($company['name']) ? $company['name'] : 'TourOps Manager'; $cLogo = $company && !empty($company['logo_url']) ? $company['logo_url'] : ''; ?>
                <div class="gttom-pro__brandMark">
                    <?php if ($cLogo): ?>
                        <img src="<?php echo esc_url($cLogo); ?>" alt="<?php echo esc_attr($cName); ?>" />
                    <?php else: ?>
                        GT
                    <?php endif; ?>
                </div>
                <div class="gttom-pro__brandText">
                    <div class="gttom-pro__brandTitle"><?php echo esc_html($cName); ?></div>
                    <div class="gttom-pro__brandSub">TourOps Manager</div>
                </div>
            </div>
            <nav class="gttom-pro__nav">
                <?php foreach ($nav as $key => $item): ?>
                    <a class="gttom-pro__link <?php echo $key === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url($item['url']); ?>">
                        <span class="gttom-pro__icon"><?php echo svg_icon($key); ?></span>
                        <span class="gttom-pro__label"><?php echo esc_html($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="gttom-pro__sidebarFoot">
                <div class="gttom-pro__user">Signed in: <strong><?php echo $who; ?></strong></div>
            </div>
        </aside>

        <main class="gttom-pro__main">
            <header class="gttom-pro__header">
                <div class="gttom-pro__title">
                    <h1><?php echo esc_html($page_title); ?></h1>
                    <div class="gttom-pro__subtitle">Operational workspace for tours, suppliers, and execution</div>
                </div>
            </header>

            <section class="gttom-pro__content">
                <?php echo $inner_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </section>
        </main>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * OPERATOR SHORTCODES (frontend-only)
 */

add_shortcode('gttom_operator_dashboard', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }

    // Fallback router: if operator URLs are configured as a single dashboard with ?section=...
    // we render the requested section's shortcode here to avoid 404s.
    $section = isset($_GET['section']) ? sanitize_key((string)$_GET['section']) : '';
    if ($section) {
        $map = [
            'overview' => 'gttom_operator_overview',
            'build'    => 'gttom_operator_build_tour',
            'assign'   => 'gttom_operator_supplier_assignment',
            'catalog'  => 'gttom_operator_catalog',
            'tours'    => 'gttom_operator_tours',
            'timeline' => 'gttom_operator_timeline',
            'automation' => 'gttom_operator_automation',
            'agents'   => 'gttom_operator_agents_page',
            'settings' => 'gttom_operator_settings',
        ];
        if (isset($map[$section])) {
            return do_shortcode('[' . $map[$section] . ']');
        }
    }


    // Stabilization: ensure the operator record exists as soon as the dashboard loads.
    // This prevents "Request failed" on first General save when roles were assigned in WP but
    // the gttom_operators row was not yet created.
    if (class_exists('GTTOM\\Ajax')) {
        \GTTOM\Ajax::current_operator_id();
    }

    // Phase 2 (partial): Build Tour → General + Itinerary Builder
    return '<div class="gttom-wrap" id="gttom-operator-app">
        <h2>GT TourOps Manager</h2>

        <div class="gttom-tabs" id="gttom-main-tabs">
            <button class="gttom-tab is-active" data-tab="build">Build Tour</button>
            <button class="gttom-tab" data-tab="catalog">Admin Panel</button>
            <button class="gttom-tab" data-tab="tours">Tours List</button>
            <button class="gttom-tab" data-tab="agents">Agents</button>
        </div>

        <div class="gttom-panel is-active" data-panel="build">
            <div class="gttom-tabs gttom-subtabs" id="gttom-build-tabs">
                <button class="gttom-tab is-active" data-subtab="general">General</button>
                <button class="gttom-tab" data-subtab="itinerary">Itinerary Builder</button>
                <button class="gttom-tab" data-subtab="pricing" disabled>Price Calculator</button>
                <button class="gttom-tab" data-subtab="finish" disabled>Finish</button>
            </div>

            <div class="gttom-subpanel is-active" data-subpanel="general">
                <div class="gttom-card">
                    <h3>Tour General</h3>
                    <div class="gttom-form-grid">
                        <label>Tour name
                            <input type="text" id="gttom-tour-name" placeholder="e.g. Uzbekistan Classic 10D" />
                        </label>
                        <label>Start date
                            <input type="date" id="gttom-tour-start" />
                        </label>
                        <label>Pax
                            <input type="number" id="gttom-tour-pax" min="1" value="1" />
                        </label>
                        <label>Currency
                            <input type="text" id="gttom-tour-currency" value="USD" maxlength="3" />
                        </label>
                        <label>VAT rate (%)
                            <input type="number" id="gttom-tour-vat" min="0" step="0.01" value="0" />
                        </label>
                        <label>Tour status
                            <select id="gttom-tour-status">
                                <option value="draft" selected>draft</option>
                                <option value="in_progress">in_progress</option>
                                <option value="completed">completed</option>
                            </select>
                        </label>
                    </div>

                    <div class="gttom-actions">
                        <button class="gttom-btn" id="gttom-tour-save">Save (AJAX)</button>
                        <span class="gttom-muted">Tour ID: <strong id="gttom-tour-id">—</strong></span>
                    </div>
                    <div id="gttom-tour-msg" class="gttom-msg"></div>
                </div>
            </div>

            <div class="gttom-subpanel" data-subpanel="itinerary">
                <div class="gttom-card">
                    <h3>Itinerary Builder</h3>
                    <p class="gttom-note">Phase 2: days + steps CRUD. Status defaults to <strong>not_booked</strong>.</p>

                    <div class="gttom-actions" id="gttom-itin-actions" data-requires-tour="1">
                        <button class="gttom-btn" id="gttom-add-day">Add Day</button>
                        <button class="gttom-btn gttom-btn-secondary" id="gttom-refresh-tour">Refresh</button>
                        <button class="gttom-btn" id="gttom-save-all">Save Tour</button>
                    </div>

					<div id="gttom-days"></div>
					<div id="gttom-itin-msg" class="gttom-msg"></div>
                </div>
            </div>
        </div>

        <div class="gttom-panel" data-panel="catalog">
            <div class="gttom-catalog">
                <div class="gttom-catalog-nav">
                    <button class="gttom-cnav is-active" data-entity="cities">Cities</button>
                    <button class="gttom-cnav" data-entity="hotels">Hotels</button>
                    <button class="gttom-cnav" data-entity="guides">Activities</button>
                    <button class="gttom-cnav" data-entity="transfers">Transfers</button>
                    <button class="gttom-cnav" data-entity="pickups">Pick-ups / Drop-offs</button>
                    <button class="gttom-cnav" data-entity="full_day_cars">Full-day Cars</button>
                                        <button class="gttom-cnav" data-entity="meals">Meals</button>
                    <button class="gttom-cnav" data-entity="fees">Fees</button>
                    <button class="gttom-cnav" data-entity="tour_packages">Tour Packages</button>
                    <button class="gttom-cnav" data-entity="suppliers">Suppliers</button>
                </div>

                <div class="gttom-catalog-body">
                    <div class="gttom-card">
                        <div class="gttom-catalog-head">
                            <h3 id="gttom-catalog-title">Cities</h3>
                            <button class="gttom-btn" id="gttom-catalog-add">Add New</button>
                        </div>
                        <div class="gttom-note" id="gttom-catalog-hint">
                            Catalog items are templates. Editing them never changes existing tours.
                        </div>

                        <div class="gttom-catalog-form" id="gttom-catalog-form" style="display:none;">
                            <input type="hidden" id="gttom-cat-id" value="0" />
                            <div class="gttom-form-grid">
                                <label>Name
                                    <input type="text" id="gttom-cat-name" placeholder="Name" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-country" style="display:none;">Country
                                    <input type="text" id="gttom-cat-country" placeholder="e.g. Uzbekistan" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-itinerary" style="display:none;">Itinerary text (shows in Ops Console)
                                    <textarea id="gttom-cat-itinerary" placeholder="Short itinerary line for console"></textarea>
                                </label>

                                <label class="gttom-cat-field gttom-cat-duration" style="display:none;">Duration (optional)
                                    <input type="text" id="gttom-cat-duration" placeholder="e.g. 3 hours / 1 day" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-pricing-mode" style="display:none;">Pricing mode
                                    <select id="gttom-cat-pricing-mode">
                                        <option value="per_person">Per person</option>
                                        <option value="per_group">Per group</option>
                                    </select>
                                </label>

                                <label class="gttom-cat-field gttom-cat-price-pp" style="display:none;">Price per person
                                    <input type="number" min="0" step="0.01" id="gttom-cat-price-pp" placeholder="e.g. 25" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-price-group" style="display:none;">Price per group
                                    <input type="number" min="0" step="0.01" id="gttom-cat-price-group" placeholder="e.g. 120" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-car-type" style="display:none;">Car type
                                    <input type="text" id="gttom-cat-car-type" placeholder="e.g. Chevrolet Cobalt" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-price-car" style="display:none;">Price per car
                                    <input type="number" min="0" step="0.01" id="gttom-cat-price-car" placeholder="e.g. 90" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-meal-type" style="display:none;">Meal type
                                    <input type="text" id="gttom-cat-meal-type" placeholder="e.g. Lunch / Dinner" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-pricing-policy" style="display:none;">Pricing policy / Room & service notes
                                    <textarea id="gttom-cat-pricing-policy" placeholder="Optional policy or room pricing notes"></textarea>
                                </label>

                                <label class="gttom-cat-field gttom-cat-supplier-type" style="display:none;">Supplier type
                                    <select id="gttom-cat-supplier-type">
                                        <option value="global">Global</option>
                                        <option value="guide">Guide</option>
                                        <option value="driver">Driver</option>
                                    </select>
                                </label>

                                <label class="gttom-cat-field gttom-cat-phone" style="display:none;">Phone
                                    <input type="text" id="gttom-cat-phone" placeholder="+998…" />
                                </label>

                                <label class="gttom-cat-field gttom-cat-email" style="display:none;">Email
                                    <input type="email" id="gttom-cat-email" placeholder="name@example.com" />
                                </label>


                                <label class="gttom-cat-field gttom-cat-city" style="display:none;">City
                                    <select id="gttom-cat-city-id"></select>
                                </label>

                                <label class="gttom-cat-field gttom-cat-from" style="display:none;">From City
                                    <select id="gttom-cat-from-city-id"></select>
                                </label>

                                <label class="gttom-cat-field gttom-cat-to" style="display:none;">To City
                                    <select id="gttom-cat-to-city-id"></select>
                                </label>

                                <label class="gttom-cat-field gttom-cat-capacity" style="display:none;">Capacity
                                    <input type="number" min="1" id="gttom-cat-capacity" placeholder="e.g. 3" />
                                </label>

                                <label>Notes (optional)
                                    <textarea id="gttom-cat-meta" placeholder="Optional notes"></textarea>
                                </label>

                                <label class="gttom-inline">
                                    <input type="checkbox" id="gttom-cat-active" checked />
                                    Active
                                </label>
                            </div>

                            <div class="gttom-actions">
                                <button class="gttom-btn" id="gttom-cat-save">Save</button>
                                <button class="gttom-btn gttom-btn-ghost" id="gttom-cat-cancel">Cancel</button>
                            </div>
                        </div>

                        <div class="gttom-table-wrap">
                            <table class="gttom-table" id="gttom-catalog-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th class="gttom-col-city">City</th>
                                        <th class="gttom-col-from">From</th>
                                        <th class="gttom-col-to">To</th>
                                        <th class="gttom-col-capacity">Capacity</th>
                                        <th>Status</th>
                                        <th style="width:160px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="gttom-catalog-rows">
                                    <tr><td colspan="7" class="gttom-note">Loading…</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div id="gttom-catalog-msg" class="gttom-note"></div>
                    </div>
                </div>
            </div>
        </div>


        <div class="gttom-panel" data-panel="tours">
            <div class="gttom-card"><h3>Tours List</h3><p class="gttom-note">Phase 5 scope: placeholder. Will show health + exactly what is not booked.</p></div>
        </div>

        <div class="gttom-panel" data-panel="agents">
            <div class="gttom-card">
                <h3>Agents</h3>
                <p class="gttom-note">Phase 3: add existing WordPress users (role: TourOps Agent) and assign them to tours.</p>

                <div class="gttom-form-row" style="gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <label style="min-width:260px;">Agent email (WordPress user)
                        <input type="email" id="gttom-agent-email" placeholder="agent@example.com" />
                    </label>
                    <button class="gttom-btn" id="gttom-agent-add">Add Agent</button>
                    <span class="gttom-muted">Tip: set the user role to <strong>TourOps Agent</strong> in WordPress Users first.</span>
                </div>

                <div id="gttom-agent-msg" class="gttom-msg"></div>

                <div class="gttom-table-wrap" style="margin-top:10px;">
                    <table class="gttom-table">
                        <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th style="width:320px;">Assign to Tour</th>
                        </tr>
                        </thead>
                        <tbody id="gttom-agents-rows">
                            <tr><td colspan="4" class="gttom-note">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
          </div>
        </div>
    </div>';
});

/**
 * Option A operator pages (separate pages)
 */

add_shortcode('gttom_operator_overview', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) {
        \GTTOM\Ajax::current_operator_id();
    }

    global $wpdb;
    $opId = class_exists('GTTOM\\Ajax') ? (int) \GTTOM\Ajax::current_operator_id() : 0;
    if (!$opId) return denied();

	// Phase 6.1: company-scoped tours (operators in the same company share tours)
	$company_id = class_exists('GTTOM\\DB') ? (int) \GTTOM\DB::current_company_id(get_current_user_id()) : 0;
	if (!$company_id) return denied();

    $toursT = DB::table('tours');
    $stepsT = DB::table('tour_steps');
    $taT    = DB::table('tour_agents');

	$totalTours = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$toursT} WHERE company_id=%d", $company_id));
	$inProg     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$toursT} WHERE company_id=%d AND status='in_progress'", $company_id));
	$draft      = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$toursT} WHERE company_id=%d AND status='draft'", $company_id));

	$notBooked  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stepsT} s INNER JOIN {$toursT} t ON t.id=s.tour_id WHERE t.company_id=%d AND s.status='not_booked'", $company_id));
	$pending    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stepsT} s INNER JOIN {$toursT} t ON t.id=s.tour_id WHERE t.company_id=%d AND s.status='pending'", $company_id));
	$unassigned = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stepsT} s INNER JOIN {$toursT} t ON t.id=s.tour_id WHERE t.company_id=%d AND s.supplier_type IS NOT NULL AND s.supplier_type<>'' AND (s.supplier_id IS NULL OR s.supplier_id=0)", $company_id));
	$agents     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ta.agent_id) FROM {$taT} ta INNER JOIN {$toursT} t ON t.id=ta.tour_id WHERE t.company_id=%d", $company_id));

	$recent = $wpdb->get_results($wpdb->prepare("SELECT id,name,start_date,status FROM {$toursT} WHERE company_id=%d ORDER BY id DESC LIMIT 10", $company_id));

    // Phase 7 UX: onboarding checklist signals (company-scoped)
    $citiesT    = DB::table('cities');
    $suppliersT = DB::table('suppliers');
    $citiesCount   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$citiesT} WHERE company_id=%d AND is_active=1", $company_id));
    $suppliersCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$suppliersT} WHERE company_id=%d AND is_active=1", $company_id));
    // Telegram connected: stored in suppliers.meta_json.telegram_chat_id (string). Use LIKE to avoid DB JSON function requirements.
    $tgConnected = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$suppliersT} WHERE company_id=%d AND is_active=1 AND meta_json LIKE %s AND meta_json NOT LIKE %s",
        $company_id,
        '%"telegram_chat_id"%',
        '%"telegram_chat_id":""%'
    ));

    ob_start();
    ?>
    <div class="gttom-onboard" data-company-id="<?php echo (int)$company_id; ?>" data-cities="<?php echo (int)$citiesCount; ?>" data-suppliers="<?php echo (int)$suppliersCount; ?>" data-tg="<?php echo (int)$tgConnected; ?>" data-tours="<?php echo (int)$totalTours; ?>">
        <div class="gttom-onboard__head">
            <div>
                <div class="gttom-onboard__title">Getting started checklist</div>
                <div class="gttom-onboard__sub">Set up the essentials once. After that, building and operating tours becomes fast and predictable.</div>
            </div>
            <button type="button" class="gttom-btn gttom-btn-small gttom-btn-ghost" data-action="dismissOnboarding">Dismiss</button>
        </div>
        <div class="gttom-onboard__items">
            <div class="gttom-onboard__item" data-check="cities">
                <span class="gttom-onboard__dot" aria-hidden="true"></span>
                <div><strong>Add cities</strong><div class="gttom-muted">Used across all catalog items and tours (company-wide).</div></div>
                <a class="gttom-proLink" href="<?php echo esc_url(operator_url('catalog')); ?>">Open Catalog</a>
            </div>
            <div class="gttom-onboard__item" data-check="suppliers">
                <span class="gttom-onboard__dot" aria-hidden="true"></span>
                <div><strong>Add suppliers</strong><div class="gttom-muted">Hotels, guides, transport, etc. (company-scoped).</div></div>
                <a class="gttom-proLink" href="<?php echo esc_url(operator_url('catalog')); ?>">Open Catalog</a>
            </div>
            <div class="gttom-onboard__item" data-check="tg">
                <span class="gttom-onboard__dot" aria-hidden="true"></span>
                <div><strong>Connect Telegram</strong><div class="gttom-muted">Optional, but recommended for faster confirmations.</div></div>
                <a class="gttom-proLink" href="<?php echo esc_url(operator_url('catalog')); ?>">Open Suppliers</a>
            </div>
            <div class="gttom-onboard__item" data-check="tours">
                <span class="gttom-onboard__dot" aria-hidden="true"></span>
                <div><strong>Create your first tour</strong><div class="gttom-muted">Build structure in Build Tour, then operate in Ops Manager.</div></div>
                <a class="gttom-proLink" href="<?php echo esc_url(operator_url('build')); ?>">Open Build Tour</a>
            </div>
        </div>
    </div>
    <div class="gttom-proGrid">
        <div class="gttom-kpis">
            <div class="gttom-kpi"><div class="gttom-kpi__label">Tours</div><div class="gttom-kpi__value"><?php echo (int)$totalTours; ?></div><div class="gttom-kpi__sub">Total</div></div>
            <div class="gttom-kpi"><div class="gttom-kpi__label">In progress</div><div class="gttom-kpi__value"><?php echo (int)$inProg; ?></div><div class="gttom-kpi__sub">Active operations</div></div>
            <div class="gttom-kpi"><div class="gttom-kpi__label">Draft</div><div class="gttom-kpi__value"><?php echo (int)$draft; ?></div><div class="gttom-kpi__sub">Planning</div></div>
            <div class="gttom-kpi is-warn"><div class="gttom-kpi__label">Not booked</div><div class="gttom-kpi__value"><?php echo (int)$notBooked; ?></div><div class="gttom-kpi__sub">Steps</div></div>
            <div class="gttom-kpi is-mid"><div class="gttom-kpi__label">Pending</div><div class="gttom-kpi__value"><?php echo (int)$pending; ?></div><div class="gttom-kpi__sub">Steps</div></div>
            <div class="gttom-kpi"><div class="gttom-kpi__label">Unassigned</div><div class="gttom-kpi__value"><?php echo (int)$unassigned; ?></div><div class="gttom-kpi__sub">Suppliers</div></div>
            <div class="gttom-kpi"><div class="gttom-kpi__label">Agents</div><div class="gttom-kpi__value"><?php echo (int)$agents; ?></div><div class="gttom-kpi__sub">Assigned</div></div>
        </div>

        <div class="gttom-cardPro">
            <div class="gttom-cardPro__head">
                <h2>Recent tours</h2>
                <a class="gttom-proLink" href="<?php echo esc_url(operator_url('build')); ?>">Create / Edit tours</a>
            </div>

            <div class="gttom-table-wrap">
                <table class="gttom-table">
                    <thead><tr><th>ID</th><th>Name</th><th>Start</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="5" class="gttom-note">No tours yet. Start by creating a tour in <strong>Build Tour</strong>.</td></tr>
                    <?php else: foreach ($recent as $r): ?>
                        <tr>
                            <td>#<?php echo (int)$r->id; ?></td>
                            <td><?php echo esc_html($r->name); ?></td>
                            <td><?php echo $r->start_date ? esc_html($r->start_date) : '—'; ?></td>
                            <td><span class="gttom-badge is-<?php echo esc_attr($r->status); ?>"><?php echo esc_html($r->status); ?></span></td>
                            <td style="white-space:nowrap;">
                                <a class="gttom-btn gttom-btn-small gttom-btn-ghost" href="<?php echo esc_url(add_query_arg(['tour_id'=>(int)$r->id], operator_url('timeline'))); ?>">Timeline</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    $inner = (string) ob_get_clean();
    return operator_shell('overview', 'Overview', $inner);
});

add_shortcode('gttom_operator_build_tour', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) { \GTTOM\Ajax::current_operator_id(); }


// Phase 5.1.9: Ops Console opens ONLY from Tours List button (view=ops). Builder remains default.
$tour_id = 0;
$tid1 = isset($_GET['tour_id']) ? absint($_GET['tour_id']) : 0;
$tid2 = isset($_GET['tour']) ? absint($_GET['tour']) : 0;
$tour_id = $tid1 ?: $tid2;
$view    = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';

if ($tour_id && $view === 'ops') {
    $builder_url = add_query_arg(['tour_id' => $tour_id, 'view' => 'builder']);
    $back_url    = operator_url('tours');
    $timeline_url = add_query_arg(['tour_id' => $tour_id], operator_url('timeline'));

    ob_start();
    ?>
    <div class="gttom-wrap" id="gttom-ops-shell">
      <div class="gttom-executionBanner" role="note" aria-label="Execution mode">
        <div class="gttom-executionBanner__icon">⚙️</div>
        <div class="gttom-executionBanner__text">
          <div class="gttom-executionBanner__title">Execution Mode</div>
          <div class="gttom-executionBanner__sub">Changes here affect live operations and suppliers.</div>
        </div>
      </div>

      <div class="gttom-ops-ui" id="gttom-ops-root"
           data-tour-id="<?php echo esc_attr($tour_id); ?>"
           data-back-url="<?php echo esc_url($back_url); ?>"
           data-builder-url="<?php echo esc_url($builder_url); ?>"
           data-timeline-url="<?php echo esc_url($timeline_url); ?>"></div>
    </div>
    <?php
    $inner = (string) ob_get_clean();
    if (function_exists('\\GTTOM\\operator_shell')) {
        return (string) \GTTOM\operator_shell('tours', 'Ops Manager', $inner);
    }
    return $inner;
}

    // Reuse the existing Build Tour HTML but without the big top-level tabs.
    // Phase 7 UX: planning mode banner to reinforce separation (suppliers + confirmations live in Ops Manager).
    $html = <<<HTML
<div class="gttom-wrap" id="gttom-operator-app">
  <div class="gttom-tourws" id="gttom-tourws">
    <div class="gttom-planningBanner" role="note" aria-label="Planning Mode">
      <div class="gttom-planningBanner__icon">🧩</div>
      <div class="gttom-planningBanner__text">
        <div class="gttom-planningBanner__title">Planning Mode</div>
        <div class="gttom-planningBanner__sub">This builder is for itinerary planning only. No bookings or supplier execution happens here. Supplier assignment, requests, and confirmations are handled in <strong>Ops Manager → Tours</strong>.</div>
      </div>
    </div>
    <div class="gttom-tourws__head">
      <div class="gttom-tourws__title">
        <h2>Tour Workspace</h2>
        <div class="gttom-muted">Build the itinerary with day cards. Each day contains steps (hotel, transfer, activity, etc.).</div>
      </div>

      <div class="gttom-tourws__right">
        <div class="gttom-tabs gttom-subtabs" id="gttom-build-tabs">
          <button class="gttom-tab is-active" data-subtab="general">General</button>
          <button class="gttom-tab" data-subtab="itinerary">Itinerary</button>
          <button class="gttom-tab" data-subtab="pricing" disabled>Pricing</button>
          <button class="gttom-tab" data-subtab="finish" disabled>Finish</button>
        </div>

        <div class="gttom-tourws__meta">
          <span class="gttom-pill">Tour ID: <strong id="gttom-tour-id">—</strong></span>
        </div>
      </div>
    </div>

    <div class="gttom-tourws__body">
      <!-- GENERAL -->
      <div class="gttom-subpanel is-active" data-subpanel="general">
        <div class="gttom-tourws__panel">
          <div class="gttom-tourws__panelhead">
            <div class="gttom-tourws__paneltitle">General</div>
            <div class="gttom-muted">Creates or updates the tour record. Days can be added after saving.</div>
          </div>

          <div class="gttom-form-grid">
            <label>Tour name
              <input type="text" id="gttom-tour-name" placeholder="e.g. Uzbekistan Classic 10D" />
            </label>
            <label>Start date
              <input type="date" id="gttom-tour-start" />
            </label>
            <label>Number of pax
              <input type="number" id="gttom-tour-pax" min="1" value="1" />
            </label>
            <label>Currency
              <input type="text" id="gttom-tour-currency" value="USD" maxlength="3" />
            </label>
            <label>VAT rate (%)
              <input type="number" id="gttom-tour-vat" min="0" step="0.01" value="0" />
            </label>
            <label>Status
              <select id="gttom-tour-status">
                <option value="draft" selected>draft</option>
                <option value="in_progress">in_progress</option>
                <option value="completed">completed</option>
              </select>
            </label>
          </div>

          <div class="gttom-actions">
            <button class="gttom-btn" id="gttom-tour-save">Save</button>
            <span class="gttom-muted">After saving, open the <strong>Itinerary</strong> tab to add days.</span>
          </div>

          <div id="gttom-tour-msg" class="gttom-msg"></div>
        </div>
      </div>

      <!-- ITINERARY -->
      <div class="gttom-subpanel" data-subpanel="itinerary">
        <div class="gttom-tourws__panel">
          <div class="gttom-tourws__panelhead">
            <div class="gttom-tourws__paneltitle">Itinerary</div>
            <div class="gttom-muted">Add days and operational steps. Paid steps show warnings but remain editable.</div>
          </div>

          <div class="gttom-actions" id="gttom-itin-actions" data-requires-tour="1">
            <button class="gttom-btn" id="gttom-add-day">Add Day</button>
            <button class="gttom-btn gttom-btn-secondary" id="gttom-refresh-tour">Refresh</button>
                        <button class="gttom-btn" id="gttom-save-all">Save Tour</button>
          </div>

          <div id="gttom-days"></div>
          <div id="gttom-itin-msg" class="gttom-msg"></div>
        </div>
      </div>
    </div>
  </div>
</div>
HTML;

    return operator_shell('build', 'Build Tour', $html);
});

add_shortcode('gttom_operator_supplier_assignment', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) { \GTTOM\Ajax::current_operator_id(); }

    // Supplier assignment is now done only in Ops Manager (Tours / Ops Console) to prevent confusion.
    $opsUrl = operator_url('tours');
    $html = '<div class="gttom-wrap" id="gttom-operator-app">
        <div class="gttom-card">
            <h3>Supplier Assignment</h3>
            <div class="gttom-msg gttom-msg-warn" style="display:block;">
                Supplier assignment has been moved to <strong>Ops Manager</strong>.
                Please open a tour in <strong>Tours</strong> and assign suppliers there.
            </div>
            <p><a class="gttom-btn" href="' . esc_url($opsUrl) . '">Go to Tours (Ops Manager)</a></p>
        </div>
    </div>';

    return operator_shell('assign', 'Supplier Assignment', $html);
});

add_shortcode('gttom_operator_catalog', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) { \GTTOM\Ajax::current_operator_id(); }

        // Inline catalog UI (Phase 5.1.2): no modal editor.
    // Keep markup IDs stable so existing JS continues to work.
    $html = <<<HTML
<div class="gttom-wrap" id="gttom-operator-app">
  <div class="gttom-panel is-active" data-panel="catalog">
    <div class="gttom-catalog-ui" id="gttom-catalog-ui">
      <div class="gttom-cat-grid">
        <!-- Catalog internal navigation (NOT the TourOps sidebar) -->
        <aside class="gttom-cat-nav" aria-label="Catalog Sections">
          <h2>Catalog Sections</h2>

          <button type="button" class="gttom-cnav is-active" data-entity="cities">
            <span class="gttom-cat-nav-label">Cities</span>
            <span class="gttom-cat-badge" data-badge="cities" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="hotels">
            <span class="gttom-cat-nav-label">Hotels</span>
            <span class="gttom-cat-badge" data-badge="hotels" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="guides">
            <span class="gttom-cat-nav-label">Activities</span>
            <span class="gttom-cat-badge" data-badge="guides" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="transfers">
            <span class="gttom-cat-nav-label">Transfers</span>
            <span class="gttom-cat-badge" data-badge="transfers" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="pickups">
            <span class="gttom-cat-nav-label">Pickups / Drop-offs</span>
            <span class="gttom-cat-badge" data-badge="pickups" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="full_day_cars">
            <span class="gttom-cat-nav-label">Full-day Cars</span>
            <span class="gttom-cat-badge" data-badge="full_day_cars" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="meals">
            <span class="gttom-cat-nav-label">Meals</span>
            <span class="gttom-cat-badge" data-badge="meals" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="fees">
            <span class="gttom-cat-nav-label">Fees</span>
            <span class="gttom-cat-badge" data-badge="fees" aria-hidden="true">0</span>
          </button>

          <button type="button" class="gttom-cnav" data-entity="suppliers">
            <span class="gttom-cat-nav-label">Suppliers</span>
            <span class="gttom-cat-badge" data-badge="suppliers" aria-hidden="true">0</span>
          </button>
        </aside>

        <!-- Right content -->
        <section class="gttom-cat-main">
          <div class="gttom-cat-card">
            <div class="gttom-cat-card-head">
              <div class="gttom-cat-card-head-left">
                <h3 id="gttom-catalog-title">Cities</h3>
                <div class="gttom-cat-hint" id="gttom-catalog-hint">Catalog items are templates. Editing them never changes existing tours.</div>
              </div>
              <button class="gttom-btn gttom-cat-btn-primary" id="gttom-catalog-add" type="button">+ Add</button>
            </div>

            <div class="gttom-cat-card-body">
              <!-- Inline add/edit form (IDs/classes must stay for locked JS) -->
              <div class="gttom-cat-form" id="gttom-catalog-form" style="display:none;">
                <input type="hidden" id="gttom-cat-id" value="0" />

                <div class="gttom-cat-form-grid">
                  <div class="gttom-cat-field gttom-cat-name">
                    <label for="gttom-cat-name">Name</label>
                    <input type="text" id="gttom-cat-name" placeholder="Name" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-country" style="display:none;">
                    <label for="gttom-cat-country">Country</label>
                    <input type="text" id="gttom-cat-country" placeholder="e.g. Uzbekistan" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-city" style="display:none;">
                    <label for="gttom-cat-city-id">City</label>
                    <select id="gttom-cat-city-id"></select>
                  </div>

                  <div class="gttom-cat-field gttom-cat-from" style="display:none;">
                    <label for="gttom-cat-from-city-id">From City</label>
                    <select id="gttom-cat-from-city-id"></select>
                  </div>

                  <div class="gttom-cat-field gttom-cat-to" style="display:none;">
                    <label for="gttom-cat-to-city-id">To City</label>
                    <select id="gttom-cat-to-city-id"></select>
                  </div>

                  <div class="gttom-cat-field gttom-cat-capacity" style="display:none;">
                    <label for="gttom-cat-capacity">Capacity</label>
                    <input type="number" min="1" id="gttom-cat-capacity" placeholder="e.g. 3" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-itinerary" style="display:none; grid-column: 1 / -1;">
                    <label for="gttom-cat-itinerary">Itinerary text (shows in Ops Console)</label>
                    <textarea id="gttom-cat-itinerary" placeholder="Short itinerary line for console"></textarea>
                  </div>

                  <div class="gttom-cat-field gttom-cat-duration" style="display:none;">
                    <label for="gttom-cat-duration">Duration (optional)</label>
                    <input type="text" id="gttom-cat-duration" placeholder="e.g. 3 hours / 1 day" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-pricing-mode" style="display:none;">
                    <label for="gttom-cat-pricing-mode">Pricing mode</label>
                    <select id="gttom-cat-pricing-mode">
                      <option value="per_person">Per person</option>
                      <option value="per_group">Per group</option>
                    </select>
                  </div>

                  <div class="gttom-cat-field gttom-cat-price-pp" style="display:none;">
                    <label for="gttom-cat-price-pp">Price per person</label>
                    <input type="number" min="0" step="0.01" id="gttom-cat-price-pp" placeholder="e.g. 25" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-price-group" style="display:none;">
                    <label for="gttom-cat-price-group">Price per group</label>
                    <input type="number" min="0" step="0.01" id="gttom-cat-price-group" placeholder="e.g. 120" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-car-type" style="display:none;">
                    <label for="gttom-cat-car-type">Car type</label>
                    <input type="text" id="gttom-cat-car-type" placeholder="e.g. Chevrolet Cobalt" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-price-car" style="display:none;">
                    <label for="gttom-cat-price-car">Price per car</label>
                    <input type="number" min="0" step="0.01" id="gttom-cat-price-car" placeholder="e.g. 90" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-meal-type" style="display:none;">
                    <label for="gttom-cat-meal-type">Meal type</label>
                    <input type="text" id="gttom-cat-meal-type" placeholder="e.g. Lunch / Dinner" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-pricing-policy" style="display:none; grid-column: 1 / -1;">
                    <label for="gttom-cat-pricing-policy">Pricing policy / Room & service notes</label>
                    <textarea id="gttom-cat-pricing-policy" placeholder="Optional policy or room pricing notes"></textarea>
                  </div>

                  <div class="gttom-cat-field gttom-cat-rooms" style="display:none; grid-column: 1 / -1;">
                    <div class="gttom-cat-field-label">Rooms</div>
                    <div class="gttom-hotel-rooms" id="gttom-hotel-rooms">
                      <div class="gttom-hotel-rooms__labels">
                        <div>Room type</div>
                        <div>Capacity</div>
                        <div>Price / night (per room)</div>
                        <div></div>
                      </div>
                      <div class="gttom-hotel-rooms__rows" id="gttom-hotel-rooms-rows"></div>
                      <button class="gttom-btn gttom-btn-small gttom-btn--ghost" id="gttom-hotel-rooms-add" type="button">+ Add room</button>
                    </div>
                    <textarea id="gttom-cat-rooms" style="display:none" aria-hidden="true"></textarea>
                  </div>

                  <div class="gttom-cat-field gttom-cat-supplier-type" style="display:none;">
                    <label for="gttom-cat-supplier-type">Supplier type</label>
                    <select id="gttom-cat-supplier-type">
                      <option value="global">Global</option>
                      <option value="guide">Guide</option>
                      <option value="driver">Driver</option>
                    </select>
                  </div>

                  <div class="gttom-cat-field gttom-cat-phone" style="display:none;">
                    <label for="gttom-cat-phone">Phone (visible in Ops Console)</label>
                    <input type="text" id="gttom-cat-phone" placeholder="+998…" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-email" style="display:none;">
                    <label for="gttom-cat-email">Email</label>
                    <input type="email" id="gttom-cat-email" placeholder="name@example.com" />
                  </div>

                  <div class="gttom-cat-field gttom-cat-telegram" style="display:none; grid-column: 1 / -1;">
                    <div class="gttom-cat-field-label" style="font-weight:600;">Telegram (optional)</div>
                    <div id="gttom-sup-tg-status" class="gttom-note" style="margin-top:4px;">—</div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                      <button type="button" class="gttom-btn" id="gttom-sup-tg-generate">Generate connection link</button>
                      <button type="button" class="gttom-btn gttom-btn-ghost" id="gttom-sup-tg-disconnect">Disconnect</button>
                    </div>
                    <div id="gttom-sup-tg-instructions" class="gttom-card" style="margin-top:12px; display:none;">
                      <div class="gttom-note" style="margin:0 0 8px 0;">Copy this link and send it to the supplier (email / WhatsApp). When they open it, Telegram will connect automatically.</div>

                      <div class="gttom-tgLinkRow">
                        <input type="text" id="gttom-sup-tg-link" class="gttom-input" readonly value="" />
                        <button type="button" class="gttom-btn gttom-btn-small" id="gttom-sup-tg-copy">Copy link</button>
                        <a href="#" target="_blank" rel="noopener" class="gttom-proLink" id="gttom-sup-tg-deeplink">Open</a>
                      </div>

                      <details class="gttom-tgFallback" style="margin-top:10px;">
                        <summary>Fallback (manual)</summary>
                        <div class="gttom-note" style="margin:8px 0 8px 0;">If Telegram doesn’t open, the supplier can send this command to the bot:</div>
                        <code id="gttom-sup-tg-command" style="display:block; padding:10px;">/start</code>
                      </details>

                      <div class="gttom-note" style="margin-top:10px;">This link expires in 30 minutes.</div>
                    </div>
                  </div>

                  <div class="gttom-cat-field" style="grid-column:1/-1;">
                    <label for="gttom-cat-meta">Notes (optional)</label>
                    <textarea id="gttom-cat-meta" placeholder="Optional notes"></textarea>
                  </div>

                  <div class="gttom-cat-field gttom-cat-active" style="grid-column:1/-1;">
                    <label class="gttom-inline">
                      <input type="checkbox" id="gttom-cat-active" checked />
                      Active
                    </label>
                  </div>
                </div>

                <div class="gttom-cat-form-actions">
                  <button class="gttom-btn" id="gttom-cat-save">Save</button>
                  <button class="gttom-btn gttom-btn-ghost" id="gttom-cat-cancel">Cancel</button>
                </div>
              </div>

              <div class="gttom-table-wrap gttom-cat-table-wrap">
                <table class="gttom-table gttom-cat-table" id="gttom-catalog-table">
                  <thead id="gttom-catalog-head">
                    <tr>
                      <th>Name</th>
                      <th class="gttom-col-city">City</th>
                      <th class="gttom-col-from">From</th>
                      <th class="gttom-col-to">To</th>
                      <th class="gttom-col-capacity">Capacity</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="gttom-catalog-rows"></tbody>
                </table>
              </div>

              <div id="gttom-catalog-msg" class="gttom-msg"></div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
HTML;

    return operator_shell('catalog', 'Catalog', $html);
});

add_shortcode('gttom_operator_tours', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) { \GTTOM\Ajax::current_operator_id(); }

    $build_url = esc_url(operator_url('build'));
    $timeline_url = esc_url(operator_url('timeline'));

    ob_start();
    ?>
    <div id="gttom-operator-tours" class="gttom-tours" data-build-url="<?php echo esc_attr($build_url); ?>" data-timeline-url="<?php echo esc_attr($timeline_url); ?>">
            <div class="gttom-cardPro">
              <div class="gttom-cardPro__head">
                <h2>Tours</h2>
                <div class="gttom-muted">Operational visibility: health monitoring & quick access. Health is derived from step booking statuses (no manual overrides).</div>
              </div>
              <div class="gttom-cardPro__body">
              <div class="gttom-filters gttom-tours-filters">
                <div class="gttom-filter">
                  <label>Start date from</label>
                  <input type="date" id="gttom-tours-from" />
                </div>
                <div class="gttom-filter">
                  <label>to</label>
                  <input type="date" id="gttom-tours-to" />
                </div>
    
                <div class="gttom-filter">
                  <label>Health priority</label>
                  <select id="gttom-tours-health-sort">
                    <option value="critical_first" selected>Critical first</option>
                    <option value="warning_first">Warning first</option>
                    <option value="healthy_first">Healthy first</option>
                    <option value="none">No health sorting</option>
                  </select>
                </div>
    
                <div class="gttom-filter">
                  <label>Status</label>
                  <select id="gttom-tours-status">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="in_progress">In progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
    
                <div class="gttom-filter">
                  <label>Agent</label>
                  <select id="gttom-tours-agent">
                    <option value="">All</option>
                  </select>
                </div>
    
                <div class="gttom-filter gttom-filter--grow">
                  <label>Search</label>
                  <input type="text" id="gttom-tours-q" placeholder="Tour title or ID…" />
                </div>
    
                <div class="gttom-filter gttom-filter--actions">
                  <button class="gttom-btn" id="gttom-tours-apply">Apply</button>
                  <button class="gttom-btn gttom-btn-ghost" id="gttom-tours-reset">Reset</button>
                </div>
              </div>
    
              <div id="gttom-tours-msg" class="gttom-note"></div>
              <div id="gttom-tours-list" class="gttom-tours-list">
                <div class="gttom-note">Loading…</div>
              </div>
            </div>
          </div>
    <?php
    $html = ob_get_clean();
    return operator_shell('tours', 'Tours', $html);
});

add_shortcode('gttom_operator_agents_page', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) { \GTTOM\Ajax::current_operator_id(); }

    // Reuse the existing Agents panel markup from the combined dashboard so the existing JS works.
    $html = '<div class="gttom-wrap" id="gttom-operator-app">' .
        '<div class="gttom-panel is-active" data-panel="agents">' .
        '<div class="gttom-card">' .
        '<h3>Agents</h3>' .
        '<p class="gttom-note">Add existing WordPress users (role: TourOps Agent) and assign them to tours.</p>' .
        '<div class="gttom-form-row" style="gap:10px;flex-wrap:wrap;align-items:flex-end;">' .
        '<label style="min-width:260px;">Agent email (WordPress user)<input type="email" id="gttom-agent-email" placeholder="agent@example.com" /></label>' .
        '<button class="gttom-btn" id="gttom-agent-add">Add Agent</button>' .
        '<span class="gttom-muted">Tip: set the user role to <strong>TourOps Agent</strong> in WordPress Users first.</span>' .
        '</div>' .
        '<div id="gttom-agent-msg" class="gttom-msg"></div>' .
        '<div class="gttom-table-wrap" style="margin-top:10px;">' .
        '<table class="gttom-table"><thead><tr><th>Agent</th><th>Email</th><th style="width:220px;">Assign Tour ID</th><th style="width:160px;">Action</th></tr></thead><tbody id="gttom-agent-rows"><tr><td colspan="4" class="gttom-note">Loading…</td></tr></tbody></table>' .
        '</div>' .
        '<div id="gttom-agent-assign-msg" class="gttom-msg"></div>' .
        '</div></div></div>';

    return operator_shell('agents', 'Agents', $html);
});

/**
 * Operator Settings (Telegram)
 * Usage: [gttom_operator_settings]
 */
add_shortcode('gttom_operator_settings', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    if (class_exists('GTTOM\\Ajax')) { \GTTOM\Ajax::current_operator_id(); }

    $tg_link = (string) get_option('gttom_telegram_bot_link', 'https://t.me/gtopsmanagerbot');
    $tg_link = $tg_link ? $tg_link : 'https://t.me/gtopsmanagerbot';
    $token_set = (string) get_option('gttom_telegram_bot_token', '') !== '';

    $webhook_url = home_url('/wp-json/gttom/v1/telegram-webhook');
    // Telegram requires HTTPS webhooks. We'll show the https version too.
    $webhook_https = preg_replace('~^http://~i', 'https://', $webhook_url);

    ob_start();
    ?>
    <div class="gttom-cardPro" id="gttom-settings">
      <div class="gttom-cardPro__head">
        <h2>Settings</h2>
        <div class="gttom-muted">Telegram is optional per supplier. Configure the bot token once, set the webhook, then connect suppliers inside Catalog → Suppliers.</div>
      </div>

      <div class="gttom-cardPro__body">
        <h3 style="margin:0 0 8px 0;">Telegram</h3>
        <p class="gttom-note">Webhook URL (Telegram requires HTTPS): <code><?php echo esc_html($webhook_https); ?></code></p>

        <div class="gttom-form-row">
          <label>Bot token</label>
          <input type="password" id="gttom-tg-token" placeholder="Paste token from BotFather" value="" autocomplete="new-password" />
          <div class="gttom-note">Status: <?php echo $token_set ? '<strong>Configured</strong>' : '<strong>Not configured</strong>'; ?> (token is never shown back)</div>
        </div>

        <div class="gttom-form-row">
          <label>Bot link</label>
          <input type="text" id="gttom-tg-link" value="<?php echo esc_attr($tg_link); ?>" />
          <div class="gttom-note">Used for deep-link instructions (supplier connection).</div>
        </div>

        <div class="gttom-form-row" style="gap:10px; flex-wrap:wrap; align-items:center;">
          <button type="button" class="gttom-btn" id="gttom-tg-save">Save Telegram Settings</button>
          <button type="button" class="gttom-btn gttom-btn-ghost" id="gttom-tg-set-webhook">Set Webhook</button>
          <button type="button" class="gttom-btn gttom-btn-ghost" id="gttom-tg-webhook-info">Webhook Info</button>
        </div>

        <div id="gttom-tg-msg" class="gttom-msg"></div>

        <div class="gttom-note">
          <strong>Important:</strong> If your site is only available via <code>http://</code>, Telegram webhooks will not work. Install SSL and use <code>https://</code>.
        </div>
      </div>
    </div>
    <?php
    $html = (string) ob_get_clean();
    return operator_shell('settings', 'Settings', $html);
});

add_shortcode('gttom_operator_services', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }

    // Tier pricing demo (AJAX; no reload)
    return '<div class="gttom-wrap">
        <h2>Operator Services (Skeleton)</h2>
        <p class="gttom-note">In this phase, we prove AJAX tier pricing without page reload. Next phase: full Services CRUD UI.</p>

        <div class="gttom-card">
          <h3>Tier Pricing Demo</h3>
          <div class="gttom-form-row">
            <label>Service ID <input type="number" id="gttom-service-id" min="1" /></label>
            <label>Min pax <input type="number" id="gttom-min-pax" min="1" value="1" /></label>
            <label>Max pax <input type="number" id="gttom-max-pax" min="1" value="2" /></label>
            <label>Price <input type="number" id="gttom-price" min="0" step="0.01" value="100" /></label>
            <button class="gttom-btn" id="gttom-add-tier">Add tier (AJAX)</button>
            <button class="gttom-btn gttom-btn-secondary" id="gttom-refresh-tiers">Refresh</button>
          </div>

          <div id="gttom-msg" class="gttom-msg"></div>

          <table class="gttom-table">
            <thead><tr><th>Min</th><th>Max</th><th>Price</th><th></th></tr></thead>
            <tbody id="gttom-tier-rows"><tr><td colspan="4">No data.</td></tr></tbody>
          </table>
        </div>
    </div>';
});

add_shortcode('gttom_operator_itineraries', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    return '<div class="gttom-wrap"><p class="gttom-note">Use <strong>[gttom_operator_dashboard]</strong> → Build Tour.</p></div>';
});

add_shortcode('gttom_operator_agents', function () {
    if (!require_operator()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    return '<div class="gttom-wrap">
        <h2>Operator Agents (Skeleton)</h2>
        <p class="gttom-note">Next: add/disable agents + enforce plan agent limit.</p>
    </div>';
});

/**
 * AGENT SHORTCODES (frontend-only)
 */
add_shortcode('gttom_agent_dashboard', function () {
    if (!require_agent()) return denied();
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    return '<div class="gttom-wrap" id="gttom-agent-app">
        <h2>Agent Execution</h2>

        <div class="gttom-card">
            <h3>My Assigned Tours</h3>
            <div class="gttom-form-row" style="gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <label style="min-width:320px;">Select tour
                    <select id="gttom-agent-tour-select"><option value="0">Loading…</option></select>
                </label>
                <button class="gttom-btn gttom-btn-secondary" id="gttom-agent-refresh">Refresh</button>
            </div>
            <div id="gttom-agent-msg" class="gttom-msg"></div>
        </div>

        <div class="gttom-card" style="margin-top:12px;">
            <h3>Execution View</h3>
            <p class="gttom-note">You can change step status and add notes. You cannot change suppliers, structure, or prices.</p>
            <div id="gttom-agent-tour"></div>
        </div>
    </div>';
});

/**
 * PUBLIC (client) view placeholder
 */
add_shortcode('gttom_itinerary_view', function ($atts) {
    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }
    $atts = shortcode_atts(['uuid' => ''], $atts);
    $uuid = sanitize_text_field($atts['uuid']);
    if (!$uuid) return '<div class="gttom-wrap"><p>Missing itinerary UUID.</p></div>';
    return '<div class="gttom-wrap"><h2>Itinerary</h2><p>UUID: ' . esc_html($uuid) . '</p><p class="gttom-note">Next: render itinerary days + services.</p></div>';
});

/**
 * Operator/Admin System Health (diagnostic)
 * Usage: [gttom_operator_health]
 * - Read-only; no DB schema changes.
 * - Helps diagnose "empty lists" caused by missing company context/membership.
 */
add_shortcode('gttom_operator_health', function () {
    if (!is_user_logged_in()) {
        return '<div class="gttom-wrap"><p>Please log in.</p></div>';
    }

    // Allow operators + admins.
    if (!current_user_can('gttom_operator_access') && !current_user_can('gttom_admin_access') && !current_user_can('administrator')) {
        return '<div class="gttom-wrap"><p>You do not have permission to view this page.</p></div>';
    }

    if (class_exists('GTTOM\\Assets')) { \GTTOM\Assets::ensure(); }

    $uid = get_current_user_id();
    $company_id = class_exists('GTTOM\\DB') ? (int) \GTTOM\DB::current_company_id($uid) : (int) get_user_meta($uid, 'gttom_current_company_id', true);
    $company = class_exists('GTTOM\\DB') ? \GTTOM\DB::current_company() : null;
    $company_name = is_array($company) ? (string)($company['name'] ?? '') : '';

    global $wpdb;
    $tables = [
        'companies'      => class_exists('GTTOM\\DB') ? \GTTOM\DB::table('companies') : '',
        'company_users'  => class_exists('GTTOM\\DB') ? \GTTOM\DB::table('company_users') : '',
        'operators'      => class_exists('GTTOM\\DB') ? \GTTOM\DB::table('operators') : '',
        'cities'         => class_exists('GTTOM\\DB') ? \GTTOM\DB::table('cities') : '',
        'suppliers'      => class_exists('GTTOM\\DB') ? \GTTOM\DB::table('suppliers') : '',
        'tours'          => class_exists('GTTOM\\DB') ? \GTTOM\DB::table('tours') : '',
    ];

    $membership_role = '';
    $membership_status = '';
    if ($company_id > 0 && !empty($tables['company_users'])) {
        $m = $wpdb->get_row($wpdb->prepare(
            "SELECT role, status FROM {$tables['company_users']} WHERE company_id = %d AND user_id = %d",
            $company_id,
            $uid
        ), ARRAY_A);
        if (is_array($m)) {
            $membership_role = (string)($m['role'] ?? '');
            $membership_status = (string)($m['status'] ?? '');
        }
    }

    $city_count = 0;
    $supplier_count = 0;
    $tour_count = 0;
    if ($company_id > 0) {
        // Cities/suppliers are company-shared via company_users join. Count like Ajax::catalog_list does.
        if (!empty($tables['cities']) && !empty($tables['operators']) && !empty($tables['company_users'])) {
            $city_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['cities']} t LEFT JOIN {$tables['operators']} o ON t.operator_id=o.id LEFT JOIN {$tables['company_users']} cu ON cu.user_id=o.user_id WHERE cu.company_id=%d",
                $company_id
            ));
        }
        if (!empty($tables['suppliers']) && !empty($tables['operators']) && !empty($tables['company_users'])) {
            $supplier_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['suppliers']} t LEFT JOIN {$tables['operators']} o ON t.operator_id=o.id LEFT JOIN {$tables['company_users']} cu ON cu.user_id=o.user_id WHERE cu.company_id=%d",
                $company_id
            ));
        }
        if (!empty($tables['tours'])) {
            $tour_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['tours']} WHERE company_id=%d",
                $company_id
            ));
        }
    }

    $tg_token_set = (string) get_option('gttom_telegram_bot_token', '') !== '';
    $tg_link = (string) get_option('gttom_telegram_bot_link', 'https://t.me/gtopsmanagerbot');

    ob_start();
    ?>
    <div class="gttom-wrap">
        <h2>System Health</h2>
        <div class="gttom-card">
            <table class="widefat striped" style="max-width:980px;">
                <tbody>
                <tr><th>User</th><td><?php echo esc_html((string)$uid); ?> (<?php echo esc_html(wp_get_current_user()->user_login); ?>)</td></tr>
                <tr><th>Company ID</th><td><?php echo esc_html((string)$company_id); ?></td></tr>
                <tr><th>Company name</th><td><?php echo esc_html($company_name ?: '—'); ?></td></tr>
                <tr><th>Membership</th><td><?php echo esc_html(($membership_role ?: '—') . ($membership_status ? ' / ' . $membership_status : '')); ?></td></tr>
                <tr><th>Cities (company scope)</th><td><?php echo esc_html((string)$city_count); ?></td></tr>
                <tr><th>Suppliers (company scope)</th><td><?php echo esc_html((string)$supplier_count); ?></td></tr>
                <tr><th>Tours (company scope)</th><td><?php echo esc_html((string)$tour_count); ?></td></tr>
                <tr><th>Telegram configured</th><td><?php echo $tg_token_set ? '<strong>Yes</strong>' : '<strong>No</strong>'; ?><?php if ($tg_link) echo ' — ' . esc_html($tg_link); ?></td></tr>
                </tbody>
            </table>
            <?php if ($company_id <= 0): ?>
                <div class="notice notice-error" style="margin-top:12px;"><p><strong>Company context is missing.</strong> This will cause Suppliers/Cities/Catalog to appear empty. Fix by ensuring your user is added to a company and has a current company selected.</p></div>
            <?php elseif (!$membership_role): ?>
                <div class="notice notice-warning" style="margin-top:12px;"><p><strong>Membership row not found.</strong> Your operator may not be linked in <code>company_users</code>. Suppliers/Cities queries join through memberships.</p></div>
            <?php endif; ?>
        </div>
        <p class="gttom-note">This page is read-only. It helps diagnose missing company context and empty catalog lists.</p>
    </div>
    <?php
    return ob_get_clean();
});