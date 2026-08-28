# Clean Stock Ledger and Current Balance Design

## Objective

Make the `all` and `clean` inventory-history views use the same current stock
truth as the inventory endpoint while keeping the chronology readable.

The row balance remains the balance immediately after that movement. A separate
`current_balance` and `current_available_balance` is returned for the current
snapshot of the selected item and location. This prevents a filtered clean
history from appearing to have a different current stock merely because older
order or staging rows are hidden.

## Client-aligned semantics

- `all` is the complete auditable ledger, including reservations, releases,
  staging, corrections, and physical movements.
- `clean` is the physical chronology used for stock opname: final putaway,
  physical picking, returns, physical transfers, and cancellation-related
  physical returns.
- A normal order reservation, webhook status change, or non-physical release is
  not a clean physical movement.
- Historical backfill rows from the prior system are included only when explicitly marked by
  `created_by=system:backfill`; they are labeled as historical picking
  so the final stock remains explainable without pretending it was scanned in
  this system.
- A normal future `ORDER_COMPLETE_OUT` is not silently treated as a scan; the
  picking movement remains the clean event.
- The clean view never exposes DEFAULT staging rows as physical rack events.

## API contract

`InventoryMovementResource` keeps `balance` and `placed_balance` as the
historical row balance. It additionally exposes:

- `current_balance`: current placed on-hand from `inventories`, matching the
  inventory endpoint for the same item/location scope.
- `current_available_balance`: current placed on-hand less current on-order,
  using the same source as the inventory endpoint.

The current fields are calculated by a grouped SQL subquery and are read-only.
They are not affected by pagination, hidden rows, or the selected history view.

## Clean filter rules

The clean query includes only physical events:

1. `PUTAWAY_IN` into a final (non-inbound) bin.
2. `PICKING` and `PICKING_REVERSAL` from a final bin.
3. `SALES_RETURN`.
4. Physical transfer sources on a final bin.
5. `ORDER_COMPLETE_OUT` and its reversal only when `created_by` is the
   historical backfill marker and the event is tied to a final bin.
6. `ORDER_RELEASE` only when it is a physical cancellation return with a
   final-bin movement and a completed order document; an unassigned release is
   not a physical event.

All other reservation, order, staging, invoice, and unassigned rows remain in
`all` or are intentionally excluded from `clean`.

## Frontend behavior

The detail view shows a compact “Stok saat ini” snapshot in clean mode using
`current_balance` and `current_available_balance`. The table continues to show
the historical row balance under “Saldo setelah aktivitas”, so users can read
the sequence and the current total without conflating the two meanings.

The monitor chronology exposes the same current snapshot field as an optional
“Stok saat ini” value while retaining its historical “Saldo” column.

## Correctness, security, and performance

- No write occurs while reading history or current balances.
- All user filters remain Query Builder bindings; SQL fragments are static and
  contain no user-provided values.
- Current stock is grouped by item and, when filtered, location, avoiding an
  N+1 query per movement row.
- Historical balances remain windowed by item/location and ordered by
  transaction date plus movement ID for deterministic results.
- Backfill inclusion is explicit and auditable through the existing actor
  marker and source label.

## Acceptance criteria

- For `DENIM-BROWN-IP-17-PRO` at Gudang Kecil, `all` and `clean` expose the
  same current snapshot (`-41` on-hand at the time of the audit), even though
  the clean row history is shorter.
- A new reservation is absent from clean and does not change the clean current
  snapshot.
- A normal non-backfill completion is absent from clean.
- A marked historical backfill completion is present and labeled as historical
  picking.
- Picking, return, and final-bin transfer events are present in clean.
- DEFAULT staging rows are absent from clean.
- Tests cover filtered and unfiltered current-balance scope, clean inclusion
  and exclusion rules, resource serialization, and deterministic row balance.
