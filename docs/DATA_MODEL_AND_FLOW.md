# GT TourOps Manager — Data Model & Flow

## Canonical Hierarchy (LOCKED)

Tour
 └── Day
      └── Step
           ├── Supplier (optional)
           ├── Status
           └── Status Logs (immutable)

## Core Tables
- gttom_tours
- gttom_tour_days
- gttom_tour_steps
- gttom_status_log
- gttom_tour_agents

## Step Status System
- not_booked
- pending
- booked
- paid

Rules:
- Status belongs to step instance
- All transitions are manual
- Status logs are append-only

## Mutability Rules
- Mutable:
  - Tour structure
  - Steps
  - Suppliers
- Immutable:
  - Status logs
