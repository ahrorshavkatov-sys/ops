=== GT TourOps Manager (Phase 0.2.1) ===
Frontend-only dashboards for Operators & Agents. Admin controls in wp-admin.

== What this ZIP contains ==
- Dedicated DB tables: wp_gttom_*
- Roles & caps: gttom_operator, gttom_agent, admin caps
- Frontend shortcodes (Operator + Agent dashboards)
- Blocks wp-admin for Operator/Agent (redirect to frontend URLs)
- AJAX tier pricing demo (no page reload)

== Required pages (create in WordPress) ==
Operator:
- /operator/  -> [gttom_operator_dashboard]
- /operator/services -> [gttom_operator_services]
- /operator/itineraries -> [gttom_operator_itineraries]
- /operator/agents -> [gttom_operator_agents]

Agent:
- /agent/ -> [gttom_agent_dashboard]

Optional public:
- /itinerary/ -> [gttom_itinerary_view uuid="..."]

== Admin settings ==
wp-admin -> TourOps Manager -> Frontend URLs
Set the redirect URLs used when operator/agent tries to open wp-admin.
