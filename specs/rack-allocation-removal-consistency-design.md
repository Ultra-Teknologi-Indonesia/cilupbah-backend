# Feature: Konsistensi tampilan setelah alokasi SKU dilepas dari rak

## Requirements

- While an SKU has been removed from a rack, when the stock detail endpoint is requested, the system shall not return an inactive zero-stock rack row as an allocation.
- While stock history must remain auditable, when an allocation is removed, the system shall preserve inventory and movement records required by the ledger.
- While a user removes an SKU from a rack, when the operation succeeds, the frontend shall refresh both rack management and inventory detail queries.

## Architecture

### Frontend

- Keep the existing remove-SKU mutation and success flow.
- Invalidate the shared inventory query namespace in addition to location-bin queries.
- Preserve the existing loading and error handling behavior.

### Backend

- Keep `sku_rack_assignments` as the source of explicit zero-stock rack allocations.
- Return placed inventory rows from `getByItem` only when they have a non-zero balance (`on_hand != 0` or `on_order != 0`), preserving visibility of anomalous negative balances for investigation.
- Do not delete `inventories` rows or movement history; zero rows may still be retained for ledger integrity and future stock writes.
- Continue merging explicit `sku_rack_assignments` into the response as zero-valued allocation rows.

### Security and data integrity

- No new endpoint or permission is introduced; the existing authenticated and authorized mutation remains the gate.
- The change is a read-side filter using Eloquent query constraints, so no user-controlled SQL is interpolated.
- Existing stock adjustment behavior remains unchanged for racks with physical stock.
- Add regression coverage for a removed allocation represented by a zero/zero inventory row.

## Implementation Plan

- [x] Audit the rack removal and stock detail read paths.
- [x] Filter inactive zero-stock inventory rows in the backend repository.
- [x] Refresh inventory queries after successful rack removal in the frontend.
- [x] Add backend regression coverage.
- [x] Run focused tests, lint/build checks, and inspect the final diff.
