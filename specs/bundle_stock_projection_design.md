# Bundle Stock Projection Design

## Objective

Make stock-position responses treat a bundle as a virtual, derived product. A bundle must never read or mutate a standalone inventory balance. Its stock is the number of complete bundles that can be assembled from its components at the same warehouse.

## Invariants

1. One canonical bundle row is returned per non-empty SKU.
2. A bundle SKU shadows legacy variant rows with the same SKU in stock-position reads; legacy rows remain untouched for auditability.
3. Bundle capacity is calculated independently per location. Component stock from different locations cannot be combined.
4. For every location:
   - `on_hand = min(floor(component.placed_on_hand / component_qty))`
   - `available = min(floor(component.available / component_qty))`
   - `on_order = on_hand - available`
5. Totals are the sum of per-location capacities, not a capacity calculated after combining warehouses.
6. Transit, inbound/default, and unassigned stock are not sellable bundle stock.
7. The endpoint is read-only. No inventory, movement, product, or bundle-composition row is changed.

## Canonical bundle resolution

Only active, non-deleted bundle products with a non-empty SKU and at least one composition row are eligible. If historical duplicates exist, the highest product ID is selected deterministically. A warning is logged with all duplicate IDs so the data can be cleaned separately without making stock reads nondeterministic.

## API contract

`GET /api/v1/inventory` and `GET /api/v1/inventory/{id}` keep the existing stock-item shape. Bundle rows use the bundle product ID as `item_id`, include per-location derived balances, and set `is_bundle=true`.

The frontend must not open rack, movement, or customer-order drill-downs for a virtual bundle because those transactions belong to component variants.

## Security and performance

- Existing authenticated inventory permissions remain unchanged.
- Search and filters are parameterized query-builder expressions.
- Pagination happens in SQL before Eloquent hydration.
- Component inventories are eager-loaded in batches for only the current page.
- Warehouse access and transit exclusions are applied before bundle derivation.

## Verification

- Unit/feature coverage for component ratios, reservations, per-location isolation, product-only bundles, duplicate legacy SKUs, deterministic canonical selection, filtering, and detail responses.
- Existing non-bundle stock-position tests must remain green.
