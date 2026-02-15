
## 5.0.6.5 (Stabilization)
- FIX: Tour list card actions (Edit/Cancel/Purge + card click) now bind globally so buttons work on Tours page.
- UI: Step editor button label changed to **Delete** (still calls step delete).
- UI: Removed redundant new-day type selector above the days list (defaults new day type to city; day type still editable per-day).

# GT TourOps Manager — Phases & Changelog

## 0.5.3 (Phase 4.2) — 2026-01-05
- Hotfix: fixed PHP parse error in operator tours shortcode markup rendering (shortcodes.php).


> **Purpose of this file:**
> - A new chat can read this and instantly know what is already built.
> - Prevents repeating old fixes and prevents architecture drift.

## Critical safety rule (to avoid site crash)

**Never keep two copies of GT TourOps Manager installed at the same time.**

When updating, always:
1) delete the old plugin folder,
2) upload the new folder,
3) activate.

## Completed Phases

### Phase 0 — Skeleton
- Plugin bootstrap
- Roles
- Frontend guardrails

### Phase 1 — Data Model
- Tours / Days / Steps tables

### Phase 2 — Build Tour
- General
- Itinerary Builder
- Catalog
- Supplier Assignment

### Phase 2.5 — Stabilization
- Operator auto-onboarding
- DB reset & hard sync
- Builder guards

### Phase 3 — Execution System
- Step status dropdown
- Agent execution dashboard
- Immutable status log

### Phase 0.4 — Professional Operator UI (Option A)
- Operator dashboard redesigned into **separate pages** (Overview / Build Tour / Supplier Assignment / Catalog / Tours / Agents)
- Left sidebar, dark professional theme (not too dark)
- **UI-only** changes: no business logic redesign

### Phase 0.4.3 — UI Polish (A+B)
- **A) Build Tour UI polish:** professional workspace header, improved day/step cards, clearer hierarchy
- **B) Catalog UI polish:** modal editor (Add/Edit in overlay), cleaner navigation styling
- Docs embedded in plugin under `/docs/` (Markdown only, never executed)

## Known Issues Fixed
- DB insert failed due to schema drift → fixed via hard reset
- Operator role not provisioned → auto-onboarding added
- Site crash from bad PHP token / duplicate plugin folders → fixed by safe packaging + guarded UI updates + install rule above

## Planned Phases
- Phase 4: Tours List Health
- Phase 5: Pricing Engine
- Phase 6: Finish & Handover

## What is LOCKED (do not redesign)
- Architecture (frontend-first, shortcodes, AJAX, custom DB tables)
- Roles and permissions
- Tour → Day → Step model
- Step status + status log rules
- Operator flexibility rule (warn, never block)



### Phase 4 — Tours List & Health Radar (Operational Visibility)
- Operator Tours page now shows a **Tours List** with filters:
  - Start date range
  - Health priority sorting (critical / warning / healthy / none)
  - Status
  - Agent
  - Search (title or Tour ID)
- Each tour is rendered as a **two-part card**:
  - Upper: tour identity (title, start date, pax, agent, Tour ID)
  - Lower: health strip (🟥🟧🟩) + counts (unbooked / pending / overdue) + expandable **Operational Details**
- Health is **derived** from step booking statuses (not stored as a separate field; no overrides).
- Operational Details are lazy-loaded via AJAX and show the actual steps that are unbooked / pending / overdue.
- Builder UX improvement: **Back / Next** buttons to move through Build subtabs; Next auto-saves General as **Draft**.



### Hotfix 0.5.1 — Crash fixes + Debugging
- Fixed a PHP fatal error in `includes/ajax.php` caused by an accidental stray `public` token ("Multiple access type modifiers are not allowed").
- Added a lightweight **debug mode** note (WP_DEBUG_LOG + plugin install safety) to help diagnose future issues without exposing errors to visitors.


### 0.5.2 (Phase 4.1)
- Builder: enforce **Save General first** (locks other subtabs until tour exists)
- Itinerary: empty state shows only **Add Day**; hide day type / supplier assignment / refresh until days exist
- Tours: edit (deep-link to builder) + soft delete (cancelled) + permanent delete (confirmed)
- Tours list default hides cancelled tours
- UI: improved padding in Tours/Builder subsections


## 0.6.1 (Hotfix)
- Fixed Ops Console JS initialization (snapshot, health details, notes, status/agent actions).
- Added Suppliers entity (name, supplier type, phone, email) and enabled supplier assignment mapping for guide/driver steps.
- UI: renamed Guides → Activities; removed Drivers from catalog navigation.
- Fixed apostrophe escaping when saving catalog names (wp_unslash).
- Improved tours list health description spacing and health color backgrounds.
- Improved card padding to avoid edge-to-edge content.

## 0.6.3 (Phase 5.1 UI + Catalog alignment hotfix)
- Fixed Tours List health meta spacing + restored health dot colors (CSS class alignment).
- Builder: step type labels are human-readable (capitalized) and removed Guides/Drivers as step types.
- Catalog: removed Drivers tab; added Suppliers tab (name, type, phone, email) and kept Activities (guides table) naming.
- Builder supplier dropdown now uses Activities→Suppliers and includes Suppliers in compact catalog payload.
- Build Tour: accepts both ?tour_id= and legacy ?tour= for opening existing tours.

## 0.6.5 (Stabilization)
- Fixed frontend asset enqueue reliability so GT TOM pages consistently load JS/CSS.
- Fixed duplicate Suppliers tab in Catalog navigation.
- Fixed Tours list button handlers not binding on the Tours page.

## 0.6.6 (Routing + Builder cleanup)
- Builder: removed the redundant global "new day type" selector near Add Day/Refresh (day type is controlled per-day).
- Builder: "Add Day" now always creates a City day by default.
- Ops Console → Builder routing preserves the current tour_id (editing the same tour).

## 0.6.7 (Deep-link fix: edit the same tour)
- Builder: fixed deep-link editing by actually initializing the `?tour_id=` loader on builder pages.
  - `builderDeepLinkInit()` now runs during page init, so `?tour_id=123&view=builder` loads the existing tour instead of starting a new one.
- Notes on identifiers: the operational **Tours** system uses numeric `tour_id`. UUIDs exist for legacy "itineraries" tables but are not used for Tours editing.

## 0.6.8 (Stabilization: cancel/delete + general load + apostrophes)
- Tours list: fixed **Cancel** and **Delete Permanently** actions (missing nonce verifier caused 500 errors).
- Builder deep-link: when opening an existing tour in Builder, **General tab fields are populated** from the saved tour.
- Text normalization: added defensive cleanup for legacy escaped apostrophes (e.g., `Anor Qal\'a`) on both save + load paths.
- Tours list: removed "click anywhere" card navigation; Ops Console opens only when clicking the **tour title**.

## 5.1.0 (Supplier assignment: multi + filtered)
- Added DB table `gttom_tour_step_suppliers` for **multi-supplier** assignment per step (non-breaking; existing single supplier fields remain for compatibility).
- Builder: steps now show **supplier chips** and allow adding/removing multiple suppliers.
- Supplier dropdown is **filtered by step type** using `supplier_type` (activity/transport/manager/other).
- Ops Console: added **Suppliers** panel showing suppliers per step **with phone numbers**.
- Cleanup: step delete and tour hard-delete also remove related step↔supplier assignment rows.

## 5.1.1 (Catalog fields + supplier schema stabilization)
- Catalog: added **City country** field (stored in `gttom_cities.country`).
- Suppliers catalog: city is now **optional** (`suppliers.city_id`) and supplier types are now **Guide / Driver / Global**.
- Added safe migrations to avoid schema drift (adds missing columns + maps legacy supplier types).
- Catalog UI: added lightweight fields for Activities (itinerary/duration/pricing), Transport (car type/price), Hotels (pricing policy), Meals/Fees (price per person).
- Catalog UI: tightened action button sizing/spacing and renamed row action to **Delete**.

## 5.1.2 (Catalog inline UI + field parity)
- Catalog page (Option A) rebuilt to use an **INLINE editor** (no modal), matching locked UX.
- Catalog editor fields now have full parity with the embedded dashboard catalog:
  - Cities: country
  - Activities: itinerary text, duration, pricing mode (per person / per group), prices
  - Transfers/Pickups/Full-day cars: car type, price per car, capacity
  - Hotels: pricing policy/notes
  - Meals/Fees: price per person (+ meal type for meals)
  - Suppliers: type (Guide/Driver/Global), phone, email, optional city
- Catalog JS default supplier type corrected to **Global** to avoid save issues.
## Phase 5.1.3 — Catalog ↔ Builder + Ops Console alignment (stabilization)

- Builder: added **Catalog item** selector per step type. Selected item is stored in existing `tour_steps.supplier_type/supplier_id/supplier_snapshot` (no schema changes).
- Catalog: fixed field toggles so **Meals / Fees** show "Price per person" correctly, and transport entities show "Price per car".

## 5.1.5 (Ops Console inline controls + Hotels room rows)
- Catalog → Hotels: replaced the single textarea rooms input with **structured per-line inputs**:
  - Room type + capacity + price per night, with +Add / Remove.
  - Storage: rooms are saved as a JSON array in `meta_json.rooms` (legacy string formats are still read via a parser).
- Ops Console: itinerary view updated to a **neutral accordion layout** (based on the provided `view.html` structure).
  - Per-step inline **Change status** panel with explicit confirm.
  - Per-step inline **Add supplier** panel (filtered by step type) requiring a free-text reason.
  - Supplier remove requires a reason prompt.
  - All supplier changes create an internal note entry.

## 6.0.0 (Company foundations + migration)
- Added multi-tenant foundation tables:
  - `gttom_companies`
  - `gttom_company_users`
- Added `company_id` column to `gttom_tours` (idempotent migration).
- Auto-created a "Default Company" on install/update if none exist.
- Auto-migrated existing Operators/Agents into Default Company memberships.
- Set `user_meta[gttom_current_company_id]` for migrated users if missing.

## 6.1.0 (Company-scoped tours: enforcement)
- Operators now share tours within their company:
  - Tours list is filtered by `t.company_id = current_company_id`.
  - Tour load/save/cancel/delete are blocked if `tour.company_id` does not match current company.
- New tours are created with `company_id = current_company_id`.
- Agent tour access also checks company match in addition to assignment.
