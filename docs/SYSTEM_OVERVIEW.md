# GT TourOps Manager — System Overview (LOCKED)

## Purpose
GT TourOps Manager is a **frontend-only Tour Operations System** for DMCs.
It is designed to manage **real tour execution**, not content, not quotations only.

## Core Principles (NON-NEGOTIABLE)
- Frontend-only dashboards (no wp-admin for operations)
- AJAX-based operations inside pages
- Page-level navigation allowed (half-AJAX model)
- Tours are **instances**, not templates
- Operator flexibility rule: system warns, never blocks
- No automatic status changes
- No hard locks on paid steps
- Agents never see prices

## Architecture
- WordPress used for users & auth only
- All operational logic lives in plugin frontend
- Custom DB tables (`gttom_*`)
- No CPT usage
- No WooCommerce dependency

## What MUST NOT be changed
- Role separation (Admin / Operator / Agent)
- Tour → Day → Step hierarchy
- Step-based execution model
- Manual status transitions only


## Operational Visibility Layer (Phase 4)
- Operators have a Tours List with a **Health Radar**.
- **Status** is a manual lifecycle label (draft / in_progress / completed).
- **Health** is a read-only diagnostic derived from step booking statuses:
  - 🟥 Critical: any overdue or unbooked steps
  - 🟧 Warning: pending steps only
  - 🟩 Healthy: no unbooked/pending/overdue
- Health is **not stored** as a separate state and is not manually editable.


## Debugging
See `docs/DEBUGGING.md` for the crash recovery + logging playbook.


## Builder prerequisite (LOCKED)
- A Tour must be saved in **General** before any itinerary/execution actions are available.
- This is the only place where UI may block navigation, because it prevents orphan data.
