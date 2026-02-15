# Phase 4 — Tours List & Health Radar (LOCKED UX)

## Purpose
Provide operators with operational visibility across all tours:
- What tours are coming
- Which tours are at risk (unbooked / pending / overdue)
- A quick way to open the tour workspace

## Tour Card (LOCKED)
Each tour is rendered as a card with two sections:

### Upper section (identity)
Always visible:
- Tour title
- Start date
- Pax
- Agent (if assigned)
- Tour ID
- Status badge (draft / in_progress / completed)

### Lower section (health)
Always visible:
- Health badge (🟥 CRITICAL / 🟧 WARNING / 🟩 HEALTHY)
- Counts: unbooked / pending / overdue
- Expandable **Operational Details ▾**

Operational Details (lazy-loaded):
- Lists actual steps that are:
  - Unbooked
  - Pending
  - Overdue

## Filters (LOCKED)
- Start date range (from/to)
- Health priority sorting:
  - Critical first
  - Warning first
  - Healthy first
  - No health sorting
- Status
- Agent
- Search: tour title or Tour ID

## Health vs Status (LOCKED)
- Status = operator lifecycle label (manual)
- Health = read-only diagnostic derived from step booking statuses
- Health is NOT stored as its own state and has no manual override

## Architecture constraints (LOCKED)
- Frontend-only
- Half-AJAX (page + AJAX inside)
- No SPA and no heavy JS
- No wp-admin operations
- No data model changes


## Edit & Delete Tours (Phase 4.1)
- Clicking a tour card opens **Build Tour** for editing (`?tour_id=ID`).
- **Delete** (soft): sets tour status to `cancelled` and hides it from default list.
- **Delete Permanently**: removes the tour and all related days/steps/logs (irreversible, confirmed in UI).

## Builder Gate: Save General First
- Builder sections beyond **General** are disabled until the tour is saved at least once.
- Itinerary empty state shows only **Add Day** until a day exists.
