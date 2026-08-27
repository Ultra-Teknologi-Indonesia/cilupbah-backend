# Feature: Canonical Inventory Balance and Placement Progress

## Requirements (EARS)

- While inventory history is requested, when a movement is serialized, the system shall expose `balance` and `placed_balance` as the running balance of stock in final racks only.
- While order allocation movements exist, when history is calculated, the system shall expose allocation through `on_order_balance` and `available_balance` without reducing `placed_balance`.
- While stock is in an inbound bin, when stock summaries are calculated, the system shall expose it as `pending_placement` and shall exclude it from `on_hand` and `available`.
- While an inventory row has no bin, when stock summaries are calculated, the system shall expose it as `legacy_unassigned` and shall exclude it from `pending_placement`, `physical_total`, `on_hand`, and `available`.
- While inbound items are received or placed, when inbound data is returned, the system shall expose a derived `placement_status` and `placement_summary` from item quantities without overwriting the receiving document status.
- While older frontend or mobile clients consume the API, when new responses are deployed, the system shall retain all existing response fields and their types.

## Canonical Definitions

| Field | Definition |
| --- | --- |
| `on_hand` / `placed_balance` / legacy `balance` | Sum of physical stock in bins with `is_inbound = false`. |
| `pending_placement` / `pending_placement_balance` | Sum of physical stock in bins with `is_inbound = true`. |
| `legacy_unassigned` / `legacy_unassigned_balance` | Sum of physical stock where `bin_id IS NULL`; diagnostic only. |
| `physical_total` / `physical_total_balance` | Final-rack stock plus inbound-bin stock; excludes unassigned legacy rows. |
| `on_order` / `on_order_balance` | Active order allocation. |
| `available` / `available_balance` | Final-rack stock minus active order allocation. |

The `clean` history view remains a successful-placement view and only returns `PUTAWAY_IN` rows. The `all` view returns all supported movements and all balance dimensions.

## Placement Status

Placement status is derived, not persisted, to prevent a second mutable status column from drifting from item quantities.

| Condition | Status |
| --- | --- |
| Inbound cancelled | `CANCELLED` |
| Total received is 0 | `NOT_STARTED` |
| Total put away is 0 | `NOT_STARTED` |
| Put away is less than received | `PARTIAL` |
| Put away is equal to or greater than received | `COMPLETED` |

`status` remains the receiving/document status. `receiving_status` is added as an explicit alias. `placement_summary` contains `received_qty`, `putaway_qty`, `pending_qty`, and `progress_percent`.

## Architecture

### Frontend

- Extend TypeScript contracts with additive balance and placement fields.
- Display the canonical placed balance as “Sisa Stok”.
- Expose the other running balances as contextual details without changing the primary number.
- Prefer backend placement summary and retain a quantity-based fallback for rolling deployments.

### Backend

- Centralize snapshot partition rules in `StockSummary`.
- Calculate history windows by movement bin classification and physical/allocation source type.
- Decorate inbound list/detail payloads through one `InboundPlacementProgress` support class.
- Remove misleading no-op inbound status recomputation hooks because placement status is derived.
- Use set-based aggregate queries and SQL windows; do not load the full movement ledger into PHP.

### Security

- Existing authenticated routes and warehouse authorization remain unchanged.
- No new mutation endpoint or user input is introduced.
- SQL expressions use fixed application constants only; request values remain handled by parameterized query builder filters.
- Responses add aggregate quantities only and expose no credentials or personal data.
- Existing endpoint rate limits and audit movement records remain unchanged.

## Compatibility

- `balance` remains an integer and becomes an alias of `placed_balance`.
- Existing `pending_placement`, `on_hand`, `on_order`, and `available` fields remain present.
- FE falls back to existing item sums if the backend response does not yet contain placement summary.
- Mobile clients can ignore all additive JSON fields.

## Implementation Plan

- [x] Add canonical snapshot partition helpers and expose legacy-unassigned totals.
- [x] Add canonical history running balances and resource fields.
- [x] Add derived inbound placement progress to list/detail payloads.
- [x] Remove no-op recomputation hooks.
- [x] Update frontend contracts and history/placement presentation.
- [x] Add backend and frontend regression tests.
- [x] Run focused suites, static checks, and final diff review.

## Success Criteria

- A final-rack receipt of 4 followed by two order reservations returns `placed_balance = 4`, `on_order_balance = 2`, and `available_balance = 2`.
- An inbound-bin receipt changes only pending and physical-total balances.
- A movement with `bin_id = null` changes only legacy-unassigned balance.
- Latest history placed balance equals inventory `on_hand` when the movement ledger is complete.
- Inbound placement status transitions `NOT_STARTED -> PARTIAL -> COMPLETED` solely from item quantities.
- Existing API fields and existing mobile payload parsing remain valid.
