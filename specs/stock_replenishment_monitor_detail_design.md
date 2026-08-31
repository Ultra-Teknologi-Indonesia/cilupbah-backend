# Feature: Restock Monitor Actions and Request Detail Controls

## Requirements

- While a product is visible in **Monitor Stok > Dipesan namun habis**, when the user clicks the row action or selects multiple rows, the system shall add only those products to the single pending replenishment batch for the route.
- While a pending replenishment request is rejected, the system shall preserve its audit history and make the underlying shortage available again in Monitor Stok for a new explicit request.
- While the last item is removed from a pending request, the system shall close the empty request and make the product available again in Monitor Stok.
- While a request detail is open, when the user searches or filters, the system shall fetch a paginated result from the API without loading the entire item collection into the browser.
- While a request detail is shown, the system shall keep product, quantity, reason, and action columns within the viewport without horizontal scrolling.
- While an active order is cancelled, the replenishment item shall be recalculated automatically; its quantity is reduced or the item is removed when no shortage remains.
- While a SKU already has an active replenishment request, it shall remain visible in Monitor Stok with a clear non-actionable status so users can see the shortage without creating a duplicate.
- While a replenishment item is shown, the API shall expose structured reason metrics so the frontend does not need to parse display text.

## Architecture

### Frontend

- Monitor Stok exposes a row action and a page-level bulk action only for `Dipesan namun habis`.
- Request detail uses separate API queries for request metadata, paginated items, and filter options.
- Search is debounced; changing search, channel, or store resets the item page to 1.
- The detail table uses a fixed layout, bounded text, and responsive controls.

### Backend

- `GET /inventory/stock-replenishment/{id}/items` returns filtered, paginated request items.
- `GET /inventory/stock-replenishment/{id}/item-filters` returns channel and store options derived from orders associated with that request.
- Item search matches SKU and product name. Channel and store filters are applied with parameterized subqueries scoped to the request destination.
- Removing the final pending item marks the request `CANCELLED` with an audit reason; no hard delete is used for the request header.
- Rejecting a request keeps the rejected record for audit. Since Monitor Stok is based on active order demand, the shortage can be explicitly queued again.
- Sales order observers dispatch the after-commit reconciliation job on order creation, update, item update, and item deletion; the existing periodic command remains a safety net.
- Monitor responses expose `has_active_restock_request` for pending/accepted items, while request item responses expose `reason_detail` alongside the legacy `reason` string.

### Security

- New read endpoints use the existing `view-permintaan-restock` permission.
- Mutations keep the existing edit/delete permissions and pending-status guard.
- Query values are validated and passed as bindings; no client-provided table or column names are accepted.
- Responses expose only product and operational filter data, never channel credentials or tokens.

## Implementation Plan

- [x] Audit existing routes, repository, service, and UI.
- [x] Add paginated item/filter APIs and empty-request lifecycle handling.
- [x] Add row and bulk actions in Monitor Stok.
- [x] Add detail search, filters, pagination, and bounded table layout.
- [x] Add automatic cancellation reconciliation, active-request marker, structured reason data, regression tests, and static checks.
