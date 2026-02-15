<?php
namespace GTTOM;

if (!defined('ABSPATH')) exit;

class Capabilities {
    const ROLE_OPERATOR = 'gttom_operator';
    const ROLE_AGENT    = 'gttom_agent';

    /**
     * Create/upgrade roles and capabilities.
     *
     * IMPORTANT:
     * - add_role() does NOT update an existing role.
     * - Many sites already have these roles from earlier plugin versions.
     *
     * Therefore we always *ensure* required caps are present.
     */
    public static function add_roles_and_caps(): void {
        // Operator role
        $operator = get_role(self::ROLE_OPERATOR);
        if (!$operator) {
            $operator = add_role(self::ROLE_OPERATOR, __('TourOps Operator', 'gttom'), ['read' => true]);
            $operator = get_role(self::ROLE_OPERATOR);
        }
        if ($operator) {
            $operator_caps = [
                'read' => true,
                'gttom_operator_access' => true,
                'gttom_manage_services' => true,
                'gttom_manage_itineraries' => true,
                'gttom_manage_agents' => true,
            ];
            foreach ($operator_caps as $cap => $grant) {
                $operator->add_cap($cap, $grant);
            }
        }

        // Agent role
        $agent = get_role(self::ROLE_AGENT);
        if (!$agent) {
            $agent = add_role(self::ROLE_AGENT, __('TourOps Agent', 'gttom'), ['read' => true]);
            $agent = get_role(self::ROLE_AGENT);
        }
        if ($agent) {
            $agent_caps = [
                'read' => true,
                'gttom_agent_access' => true,
            ];
            foreach ($agent_caps as $cap => $grant) {
                $agent->add_cap($cap, $grant);
            }
        }

        // Admin caps
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                'gttom_admin_access' => true,
                'gttom_manage_plans' => true,
                'gttom_manage_operators' => true,
                'gttom_send_notices' => true,
                'gttom_view_all_data' => true,
            ];
            foreach ($caps as $cap => $grant) {
                $admin->add_cap($cap, $grant);
            }
        }
    }
}
