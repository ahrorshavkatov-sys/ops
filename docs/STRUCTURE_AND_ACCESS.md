# GT TourOps Manager — Structure & Access

This file is the **permission contract**. A new chat must not redesign roles or move capabilities between roles unless the user explicitly requests it.

## Roles

### Admin (WordPress Admin)
- Access: wp-admin
- Responsibilities:
  - Plugin settings
  - User creation & role assignment
- Cannot:
  - Build tours
  - Execute tours
  - Assign suppliers

### Operator
- Access: Frontend Operator Dashboard
- Can:
  - Build tours
  - Edit itinerary structure
  - Assign suppliers
  - Change step statuses
  - Mark steps as paid
- Sees prices

### Agent
- Access: Frontend Agent Dashboard
- Can:
  - Update step statuses (except paid)
  - Add execution notes
- Cannot:
  - Change structure
  - Assign suppliers
  - See prices

## Operator Dashboard Pages (Option A — current)

Operators work on **separate frontend pages** so the UI stays fast and professional.

Recommended slugs (admin can override via Frontend URLs settings):
- /operator/overview
- /operator/build-tour
- /operator/supplier-assignment
- /operator/catalog
- /operator/tours
- /operator/agents

Each page is rendered by a shortcode:
- `[gttom_operator_overview]`
- `[gttom_operator_build_tour]`
- `[gttom_operator_supplier_assignment]`
- `[gttom_operator_catalog]`
- `[gttom_operator_tours]`
- `[gttom_operator_agents_page]`

Each page loads normally.
Actions inside pages are AJAX.

**UI-only note (Phase 0.4.3):** Catalog Add/Edit opens a modal overlay, but it still uses the same AJAX endpoints and database tables.

## Agent Dashboard Pages
- /agent/dashboard
- /agent/tour/{id}

## Forbidden Actions
- Agents assigning suppliers
- Agents marking paid
- Admin operating tours


## Phase 4: Tours List & Health Radar (Read-only diagnostics)
- Location: Operator frontend Tours page.
- Purpose: operational visibility (what is unbooked/pending/overdue), not editing.
- No wp-admin screens added.
- Health indicators are computed from existing Tour→Day→Step data (no new permissions).
