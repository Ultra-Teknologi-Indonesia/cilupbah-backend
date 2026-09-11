# Feature: Baseline stock and rack-assignment synchronization

## Requirements

- While a baseline CSV is committed, every valid SKU/rack row shall set the
  inventory balance to the CSV quantity, including zero.
- While `--zero-missing` is enabled, every existing non-zero inventory pair in
  the target location that is not represented by an applicable CSV row shall
  be set to zero, including negative historical balances.
- Every valid SKU/rack row shall create or update the single
  `sku_rack_assignments` record for that SKU and location.
- A valid zero-quantity row shall still create/update its rack assignment.
- Invalid rows in partial mode shall not zero or remap the same SKU's existing
  inventory/assignment.
- A SKU may exist on multiple physical racks in one file. All inventory pairs
  shall be preserved; the single assignment row shall retain an existing CSV
  rack when possible, otherwise choose the highest-quantity rack with a
  stable tie breaker.

## Architecture

### Frontend

The existing Stock Cutover UI continues to upload the file and invoke the
backend import command. No new client-side mutation is required; the command's
summary remains the source for applied/zeroed/assignment counts and errors.

### Backend

- `ImportBaselineStock` validates the CSV/XLSX in bounded database chunks.
- Inventory writes remain adjustment-ledger writes and use the existing
  repository guard, so negative target quantities cannot be persisted.
- Missing-stock cleanup includes all non-zero balances and is protected from
  invalid partial rows.
- Rack assignments are synchronized with PostgreSQL bulk `upsert` in chunks,
  keyed by `(location_id, item_id)`.
- Assignment synchronization runs only for validated, non-blocking rows and
  does not delete assignments absent from the file (zero-stock allocations are
  useful addresses).

### Security and reliability

- File validation and server-side rack/location checks remain authoritative.
- All database values are bound through the query builder; no CSV value is
  interpolated into SQL.
- Chunked transactions and idempotent upserts limit memory/lock duration and
  allow safe retry after worker interruption.
- Multi-rack inventory is preserved, while the one primary assignment is chosen
  deterministically so retries cannot move it randomly.

## Implementation plan

- [x] Include historical negative balances in zero-missing cleanup.
- [x] Synchronize validated rack assignments with bulk upserts.
- [x] Preserve invalid SKU rows during partial zero-missing cleanup.
- [x] Keep valid Qty 0 rows eligible for assignment synchronization.
- [x] Add regression tests for mismatch repair, Qty 0, multi-rack inventory,
  and partial invalid protection. Historical negative cleanup uses the same
  zero-missing path and is covered by the database invariant plus the non-zero
  cleanup predicate.
