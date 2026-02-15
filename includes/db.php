<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

class DB {

    /**
     * Phase 6: Company context helpers.
     *
     * Membership is stored in gttom_company_users.
     * Current company selection is stored in user_meta: gttom_current_company_id
     */
    public static function current_company_id(int $user_id = 0): int {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) return 0;

        $company_id = (int) get_user_meta($user_id, 'gttom_current_company_id', true);
        if ($company_id && self::user_is_active_member($user_id, $company_id)) {
            return $company_id;
        }

        // Fallback: first active membership (smallest company id).
        global $wpdb;
        $cuT = self::table('company_users');
        $fallback = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT company_id FROM $cuT WHERE user_id=%d AND status='active' ORDER BY company_id ASC LIMIT 1",
            $user_id
        ));
        if ($fallback) {
            update_user_meta($user_id, 'gttom_current_company_id', $fallback);
            return $fallback;
        }

        return 0;
    }

    
    /**
     * Fetch a company row (id, name, logo_url, status, owner_user_id).
     */
    public static function get_company(int $company_id): ?array {
        if ($company_id < 1) return null;
        global $wpdb;
        $t = self::table('companies');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d LIMIT 1", $company_id), ARRAY_A);
        return $row ?: null;
    }

    public static function current_company(): ?array {
        $cid = self::current_company_id();
        if ($cid < 1) return null;
        return self::get_company($cid);
    }

public static function user_is_active_member(int $user_id, int $company_id): bool {
        if (!$user_id || !$company_id) return false;
        global $wpdb;
        $cuT = self::table('company_users');
        $ok = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $cuT WHERE company_id=%d AND user_id=%d AND status='active'",
            $company_id,
            $user_id
        ));
        return $ok > 0;
    }

    public static function user_has_company_access(int $user_id, int $company_id): bool {
        if ($company_id < 1 || $user_id < 1) return false;
        if (user_can($user_id, 'gttom_admin_access') || user_can($user_id, 'administrator')) return true;
        global $wpdb;
        $cuT = self::table('company_users');
        $ok = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $cuT WHERE company_id=%d AND user_id=%d AND status='active'",
            $company_id,
            $user_id
        ));
        return (bool) $ok;
    }

    public static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'gttom_' . $suffix;
    }

    public static function install(bool $hard_reset = false): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Hard reset (DEV) to resolve schema drift. Drops ONLY gttom_* tables.
        if ($hard_reset) {
            $prefix = $wpdb->prefix . 'gttom_';
            // Drop known tables first (order doesn't matter because we don't use FK constraints).
            $like = $wpdb->esc_like($prefix) . '%';
            $tables_to_drop = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));
            if ($tables_to_drop) {
                foreach ($tables_to_drop as $t) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->query("DROP TABLE IF EXISTS `$t`");
                }
            }
        }


        $charset_collate = $wpdb->get_charset_collate();
        $tables = [];

        $tables[] = "CREATE TABLE " . self::table('plans') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_key VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            billing_period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            max_agents INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY plan_key (plan_key)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('operators') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            company_name VARCHAR(191) NULL,
            phone VARCHAR(64) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('subscriptions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            status ENUM('active','paused','expired','cancelled') NOT NULL DEFAULT 'active',
            starts_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY plan_id (plan_id),
            KEY status (status)
        ) $charset_collate;";

        /**
         * ------------------------------------------------------------
         * Phase 6.0: Multi-tenant foundations
         * Companies + Company Users (memberships)
         * ------------------------------------------------------------
         */
        $tables[] = "CREATE TABLE " . self::table('companies') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            logo_url VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            owner_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY status (status)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('company_users') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'operator',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_user_unique (company_id, user_id),
            KEY company_id (company_id),
            KEY user_id (user_id),
            KEY role (role),
            KEY status (status)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('agents') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(191) NULL,
            email VARCHAR(191) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('services') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            type ENUM('hotel','activity','transfer','car_full_day','tour_package') NOT NULL,
            title VARCHAR(191) NOT NULL,
            description TEXT NULL,
            duration_value INT UNSIGNED NULL,
            duration_unit ENUM('hours','days') NULL,
            base_price DECIMAL(10,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            tiered_pricing TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY type (type)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('service_tiers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id BIGINT UNSIGNED NOT NULL,
            min_pax INT UNSIGNED NOT NULL,
            max_pax INT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY service_id (service_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('itineraries') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            uuid CHAR(36) NOT NULL,
            title VARCHAR(191) NOT NULL,
            status ENUM('draft','sent','archived') NOT NULL DEFAULT 'draft',
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid),
            KEY operator_id (operator_id),
            KEY status (status)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('itinerary_days') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            itinerary_id BIGINT UNSIGNED NOT NULL,
            day_index INT UNSIGNED NOT NULL,
            date_label VARCHAR(32) NULL,
            title VARCHAR(191) NULL,
            start_time VARCHAR(16) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY itinerary_id (itinerary_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('itinerary_items') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            itinerary_day_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            qty INT UNSIGNED NOT NULL DEFAULT 1,
            price_override DECIMAL(10,2) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY itinerary_day_id (itinerary_day_id),
            KEY service_id (service_id)
        ) $charset_collate;";

        
        /**
         * ------------------------------------------------------------
         * Operator Catalog tables (Phase 2.2)
         * Template data owned by operator; does NOT mutate existing tours.
         * ------------------------------------------------------------
         */
        $tables[] = "CREATE TABLE " . self::table('cities') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            country VARCHAR(191) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('hotels') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('guides') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('drivers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('suppliers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            supplier_type ENUM('guide','driver','global') NOT NULL DEFAULT 'global',
            name VARCHAR(191) NOT NULL,
            phone VARCHAR(64) NULL,
            email VARCHAR(191) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY supplier_type (supplier_type),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        /**
         * Phase 10.3 — Supplier portal tokens (read-only access)
         * Non-breaking: new table only.
         */
        $tables[] = "CREATE TABLE " . self::table('supplier_tokens') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_id BIGINT UNSIGNED NOT NULL,
            token CHAR(40) NOT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY supplier_id (supplier_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";


        $tables[] = "CREATE TABLE " . self::table('meals') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('fees') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('pickups') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('transfers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            from_city_id BIGINT UNSIGNED NULL,
            to_city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY from_city_id (from_city_id),
            KEY to_city_id (to_city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('full_day_cars') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            capacity INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY city_id (city_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('tour_packages') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY is_active (is_active),
            KEY name (name)
        ) $charset_collate;";
/**
         * ------------------------------------------------------------
         * Canonical TourOps tables (Phase 1+)
         * Tours → Days → Steps (+ tour_agents + status_log)
         * ------------------------------------------------------------
         */

        $tables[] = "CREATE TABLE " . self::table('tours') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            start_date DATE NULL,
            pax INT UNSIGNED NOT NULL DEFAULT 1,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            status ENUM('draft','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id),
            KEY status (status),
            KEY start_date (start_date)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('tour_notes') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id BIGINT UNSIGNED NOT NULL,
            operator_id BIGINT UNSIGNED NOT NULL,
            author_user_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY operator_id (operator_id),
            KEY author_user_id (author_user_id),
            KEY created_at (created_at)
        ) $charset_collate;";


$tables[] = "CREATE TABLE " . self::table('tour_days') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id BIGINT UNSIGNED NOT NULL,
            day_index INT UNSIGNED NOT NULL,
            day_type ENUM('city','intercity') NOT NULL DEFAULT 'city',
            day_date DATE NULL,
            date_override TINYINT(1) NOT NULL DEFAULT 0,
            title VARCHAR(191) NULL,
            start_time VARCHAR(16) NULL,
            notes TEXT NULL,
            city VARCHAR(191) NULL,
            from_city VARCHAR(191) NULL,
            to_city VARCHAR(191) NULL,
            city_id BIGINT UNSIGNED NULL,
            from_city_id BIGINT UNSIGNED NULL,
            to_city_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY day_index (day_index),
            KEY day_type (day_type)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('tour_steps') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id BIGINT UNSIGNED NOT NULL,
            day_id BIGINT UNSIGNED NOT NULL,
            step_index INT UNSIGNED NOT NULL,
            step_type ENUM('hotel','pickup','transfer','meal','activity','guide','fee','driver','full_day_car','tour_package','custom') NOT NULL,
            title VARCHAR(191) NOT NULL,
            description TEXT NULL,
            time VARCHAR(16) NULL,
            qty INT UNSIGNED NOT NULL DEFAULT 1,
            price_amount DECIMAL(10,2) NULL,
            price_currency CHAR(3) NULL,
            price_overridden TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('not_booked','pending','booked','paid') NOT NULL DEFAULT 'not_booked',
            notes TEXT NULL,
            supplier_type VARCHAR(64) NULL,
            supplier_id BIGINT UNSIGNED NULL,
            supplier_snapshot LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY day_id (day_id),
            KEY step_index (step_index),
            KEY step_type (step_type),
            KEY status (status)
        ) $charset_collate;";

        // Phase 5.1: Multi-supplier assignment per step
        $tables[] = "CREATE TABLE " . self::table('tour_step_suppliers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operator_id BIGINT UNSIGNED NOT NULL,
            tour_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            supplier_type VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY step_supplier_unique (step_id, supplier_id),
            KEY operator_id (operator_id),
            KEY tour_id (tour_id),
            KEY step_id (step_id),
            KEY supplier_id (supplier_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('tour_agents') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tour_agent (tour_id, agent_id),
            KEY tour_id (tour_id),
            KEY agent_id (agent_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE " . self::table('status_log') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED NOT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            from_status ENUM('not_booked','pending','booked','paid') NOT NULL,
            to_status ENUM('not_booked','pending','booked','paid') NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY step_id (step_id),
            KEY changed_by (changed_by),
            KEY created_at (created_at)
        ) $charset_collate;";

        /**
         * Phase 9.1: Ops Audit Trail (minimal, append-only)
         * Tracks ONLY status changes and supplier assign/remove/change.
         */
        $tables[] = "CREATE TABLE " . self::table('audit_log') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            tour_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            old_value VARCHAR(191) NULL,
            new_value VARCHAR(191) NULL,
            meta_json LONGTEXT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY tour_id (tour_id),
            KEY step_id (step_id),
            KEY action (action),
            KEY changed_by (changed_by),
            KEY created_at (created_at)
        ) $charset_collate;";

        /**
         * Phase 6.4: Supplier request workflow (pending -> accept/decline via email/telegram)
         * Isolated table to avoid touching core tour/step schema.
         */
        $tables[] = "CREATE TABLE " . self::table('step_supplier_requests') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL,
            channel VARCHAR(20) NULL,
            expires_at DATETIME NOT NULL,
            responded_at DATETIME NULL,
            response ENUM('accepted','declined') NULL,
            reminder_48h_sent TINYINT(1) NOT NULL DEFAULT 0,
            reminder_day_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY company_id (company_id),
            KEY step_supplier (step_id, supplier_id),
            KEY expires_at (expires_at),
            KEY response (response)
        ) $charset_collate;";

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        // ------------------------------------------------------------
        // Phase 6.0 foundation migrations (safe, idempotent)
        // ------------------------------------------------------------
        self::phase6_company_foundation_migration();

        // ------------------------------------------------------------
        // Light migrations (safe, incremental)
        // ------------------------------------------------------------
        // Cities: add country column if missing.
        $citiesT = self::table('cities');
        $col = $wpdb->get_var("SHOW COLUMNS FROM $citiesT LIKE 'country'");
        if (!$col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE $citiesT ADD COLUMN country VARCHAR(191) NULL AFTER name");
        }

        // Suppliers: ensure city_id exists and supplier_type enum matches Guide/Driver/Global.
        $supT = self::table('suppliers');
        $col = $wpdb->get_var("SHOW COLUMNS FROM $supT LIKE 'city_id'");
        if (!$col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE $supT ADD COLUMN city_id BIGINT UNSIGNED NULL AFTER operator_id");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE $supT ADD KEY city_id (city_id)");
        }
        // Map legacy supplier_type values (activity/transport/manager/other) to new ones.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("UPDATE $supT SET supplier_type='guide'  WHERE supplier_type='activity'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("UPDATE $supT SET supplier_type='driver' WHERE supplier_type='transport'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("UPDATE $supT SET supplier_type='global' WHERE supplier_type IN ('manager','other')");
        // Ensure enum definition supports new values.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("ALTER TABLE $supT MODIFY COLUMN supplier_type ENUM('guide','driver','global') NOT NULL DEFAULT 'global'");

        // Notification defaults (safe, can be overridden later with settings UI).
        if (!get_option('gttom_mail_from_name')) {
            update_option('gttom_mail_from_name', 'GT Ops Management');
        }
        if (!get_option('gttom_mail_from_email')) {
            update_option('gttom_mail_from_email', 'no-reply@2uzbekistan.com');
        }

        update_option('gttom_db_version', Plugin::DB_VERSION);
    }

    /**
     * Phase 6.0: Create default company, memberships, and add company_id to tours.
     * This is intentionally conservative: it does NOT change existing ownership logic yet.
     */
    private static function phase6_company_foundation_migration(): void {
        global $wpdb;

        $companiesT = self::table('companies');
        $companyUsersT = self::table('company_users');
        $toursT = self::table('tours');
        $operatorsT = self::table('operators');
        $agentsT = self::table('agents');

        // Ensure the tables exist (dbDelta should have created them, but be defensive).
        $companies_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $companiesT));
        $company_users_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $companyUsersT));
        if (!$companies_exists || !$company_users_exists) {
            return;
        }

        // 1) Ensure Default Company exists (ID=1 if it's the first insert; do not assume).
        $default_company_id = (int) $wpdb->get_var("SELECT id FROM $companiesT ORDER BY id ASC LIMIT 1");
        if ($default_company_id <= 0) {
            $now = current_time('mysql');

            $owner_user_id = (int) get_current_user_id();
            if ($owner_user_id <= 0) {
                $admins = get_users([
                    'role'   => 'administrator',
                    'number' => 1,
                    'fields' => 'ID',
                ]);
                if (!empty($admins)) {
                    $owner_user_id = (int) $admins[0];
                }
            }
            if ($owner_user_id <= 0) {
                $owner_user_id = 1;
            }

            $wpdb->insert(
                $companiesT,
                [
                    'name'          => 'Default Company',
                    'status'        => 'active',
                    'owner_user_id' => $owner_user_id,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ],
                ['%s','%s','%d','%s','%s']
            );
            $default_company_id = (int) $wpdb->insert_id;
        }

        if ($default_company_id <= 0) {
            return;
        }

        // 2) Add company_id to tours (company-scoped ownership will be enforced in Phase 6.1+).
        $has_company_col = $wpdb->get_var("SHOW COLUMNS FROM $toursT LIKE 'company_id'");
        if (!$has_company_col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE $toursT ADD COLUMN company_id BIGINT UNSIGNED NOT NULL DEFAULT $default_company_id AFTER operator_id");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE $toursT ADD KEY company_id (company_id)");
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query($wpdb->prepare("UPDATE $toursT SET company_id = %d WHERE company_id IS NULL OR company_id = 0", $default_company_id));

        // 3) Create memberships for existing operators + agents.
        $now = current_time('mysql');

        // Owner membership.
        $owner_user_id = (int) $wpdb->get_var("SELECT owner_user_id FROM $companiesT WHERE id = $default_company_id");
        if ($owner_user_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO $companyUsersT (company_id, user_id, role, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s)",
                    $default_company_id,
                    $owner_user_id,
                    'owner',
                    'active',
                    $now,
                    $now
                )
            );
            if (!get_user_meta($owner_user_id, 'gttom_current_company_id', true)) {
                update_user_meta($owner_user_id, 'gttom_current_company_id', $default_company_id);
            }
        }

        // Operators → company membership.
        $operator_user_ids = [];
        $operators_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $operatorsT));
        if ($operators_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $operator_user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM $operatorsT WHERE user_id IS NOT NULL");
        }
        if (!empty($operator_user_ids)) {
            foreach ($operator_user_ids as $uid) {
                $uid = (int) $uid;
                if ($uid <= 0) continue;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO $companyUsersT (company_id, user_id, role, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s)",
                        $default_company_id,
                        $uid,
                        'operator',
                        'active',
                        $now,
                        $now
                    )
                );
                if (!get_user_meta($uid, 'gttom_current_company_id', true)) {
                    update_user_meta($uid, 'gttom_current_company_id', $default_company_id);
                }
            }
        }

        // Agents → company membership (read-only will be enforced later).
        $agents_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $agentsT));
        if ($agents_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $agent_user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM $agentsT WHERE user_id IS NOT NULL");
            if (!empty($agent_user_ids)) {
                foreach ($agent_user_ids as $uid) {
                    $uid = (int) $uid;
                    if ($uid <= 0) continue;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT IGNORE INTO $companyUsersT (company_id, user_id, role, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s)",
                            $default_company_id,
                            $uid,
                            'agent',
                            'active',
                            $now,
                            $now
                        )
                    );
                    if (!get_user_meta($uid, 'gttom_current_company_id', true)) {
                        update_user_meta($uid, 'gttom_current_company_id', $default_company_id);
                    }
                }
            }
        }
    }
}
