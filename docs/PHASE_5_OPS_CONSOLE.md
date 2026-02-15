# Phase 5 — Ops Console

## Purpose
Ops Console is a **reaction console** for operators to monitor a tour and act on execution-level issues quickly.

It does **not** replace planning logic. It is a separate operational view.

## Default navigation
- Clicking a tour from **Tours List** opens **Ops Console** by default.
- Builder remains accessible via **Open Builder**.

URL pattern:
- Ops Console: `?tour_id=123`
- Builder: `?tour_id=123&view=builder`

## Snapshot
Shows:
- Tour title
- Start date, pax, currency, VAT
- Assigned agent
- Status (modal-confirmed)
- Health (derived)

## Problems-first panel
Derived from step statuses:
- `not_booked`
- `pending`
Overdue is derived from tour start date + day index/day date.

Health is derived only:
- No manual “mark resolved”.

## Internal notes
- Tour-scoped internal notes
- Timestamped and author-stamped
- Used for handovers / context
- Not shown to customers

## Ajax endpoints used
- `gttom_tour_get` (includes assigned agent fields)
- `gttom_operator_tour_health_details`
- `gttom_operator_agents_list`
- `gttom_operator_assign_agent`
- `gttom_tour_set_status`
- `gttom_tour_notes_list`
- `gttom_tour_notes_add`
- `gttom_tour_notes_delete`



### Itinerary card (Phase 5.1.3)

Ops Console now includes an **Itinerary** panel that renders all days + steps from `gttom_tour_get`, including step booking status, time, selected catalog item (from `supplier_snapshot`), and description.
