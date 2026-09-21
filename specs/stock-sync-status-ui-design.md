# Feature: Status Push Stok per SKU dan Retry Manual

## Requirements

- While a user can view inventory sync settings, when the matrix is opened, the system shall show the latest push status for every SKU/listing combination.
- While a listing has failed, when the user clicks `Sync manual`, the system shall enqueue only that listing and show `Sedang diproses` until the channel confirms success.
- When the channel push succeeds and the outbox is durably updated, the system shall show `Berhasil`; clicking the button alone must never show success.
- When a push fails, the system shall preserve the original channel error and expose the recent attempt history without exposing tokens or credentials.

## Architecture

### Frontend

- Extend the existing inventory sync matrix cells with a compact status badge.
- Show `Sync manual` only for failed/skipped listings and a `Lihat riwayat` action when an error exists.
- Use React Query invalidation after retry and lazy-fetch history only when the user opens it.
- Show internal WMS stock once per SKU: `on_hand`, `on_order`, and computed `available`.
- Keep the existing sync toggle behavior unchanged.

### Backend

- Enrich `GET /api/v1/inventory/sync-settings` with the current durable stock outbox state per listing.
- Add `POST /api/v1/inventory/sync-settings/retry` accepting one `product_channel_mapping_id`; it reuses the durable outbox service for the exact listing.
- Add `GET /api/v1/inventory/sync-settings/{mapping}/history` for recent `sync_stock` attempts.
- Use eager loading for mapping, shop/channel, and outbox data. Retry is idempotent because the existing outbox version/row lock coalesces duplicate requests.
- Build the matrix through the repository with Spatie Query Builder, a hard-capped page size, allow-listed filters/sorts, and the shared full-text `allowedSearch` macro.
- Calculate internal stock in batch for the current page and load bundle components in bulk; no stock or status query is executed per rendered row.
- Keep `product_sync_logs` as the attempt history and `channel_stock_sync_outbox` as the current delivery state.

### Security

- Require Sanctum authentication and the existing inventory edit/view permissions.
- Validate mapping IDs as UUIDs and scope the retry to an active listing with an external product ID.
- Return only channel/shop names, status, timestamps, and sanitized error text; never return access tokens or raw credentials.
- Rate-limit manual retry requests to prevent accidental repeated API calls.

## Implementation Plan

- [x] Add the technical design.
- [x] Expose outbox state in the inventory sync matrix.
- [x] Add exact-listing retry and history endpoints.
- [x] Add status badges, retry action, and history dialog in the existing matrix.
- [x] Add backend and frontend verification.
