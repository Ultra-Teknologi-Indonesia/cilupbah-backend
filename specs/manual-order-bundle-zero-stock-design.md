# Feature: Manual Order Bundle and Zero-Stock SKU Selection

## Requirements

- While a user is creating a manual order, when the product picker is opened, the existing `/inventory/stock/items` endpoint shall return active sellable variants with zero stock.
- While a bundle variant is returned by the picker, the stock value shall represent the bundle quantity derivable from its components at the selected warehouse.
- While a manual order contains a bundle with insufficient component stock, when the order is saved, the order shall remain reserved and be classified as Empty Stock until the shortage is resolved or the item is replaced.
- The endpoint URL and response shape used by existing pickers shall remain unchanged.
- Existing deleted products, deleted variants, and stale inventory rows shall not be reactivated or reassigned automatically.

## Architecture

### Frontend

- Keep the current `StockedProductPickerDialog` request and `include_zero=1` contract.
- Keep the existing row payload (`item_id`, `sku`, `total_on_hand`) so manual order, transfer, and adjustment pickers remain compatible.
- Continue to display zero stock and allow selection; server-side validation remains authoritative when the order is saved.

### Backend

- Keep `GET /api/v1/inventory/stock/items` unchanged.
- Extend its query implementation to calculate bundle stock from component variants for the selected location, using one aggregate component-stock subquery rather than loading all products into PHP.
- Preserve the existing active-variant and non-deleted-parent constraints.
- Add an idempotent data-repair path for active bundle products that have no active variant. It must be dry-run by default and require explicit apply mode.
- Update stock-shortfall detection to inspect bundle components using the same location-level availability semantics as reservation.

### Security and correctness

- Preserve the existing route permission and `WarehouseAccess` check.
- Keep location IDs and search values parameterized through query-builder bindings.
- Do not expose deleted records in the picker response.
- Apply the same component quantity multiplication and floor division used by bundle stock calculation.
- Use a transaction and duplicate checks for any explicit bundle-variant repair.

## Performance

- Aggregate inventory once per selected location and join the result to bundle components.
- Keep pagination in SQL; do not fetch the full catalog into the browser or PHP memory.
- Run the more expensive bundle aggregate only in the picker query where bundle rows are needed.

## Acceptance criteria

- An active normal variant with stock 0 is returned when `include_zero=1`.
- An active bundle variant with component-derived stock 0 is returned when `include_zero=1`.
- A bundle with insufficient components is visible in Empty Stock after manual-order creation.
- A bundle with sufficient components can proceed normally.
- Existing non-bundle picker behavior and endpoint consumers remain unchanged.
- Production data repair can be previewed without writes before deployment.
