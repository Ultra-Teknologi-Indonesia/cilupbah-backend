# Sales Return Search and Filter

## Requirements

- While viewing sales returns, when a user searches, the API shall match return number, return tracking number, return carrier, customer, channel order number, and local order number.
- While viewing sales returns, when a user selects a reason, the API shall filter all marketplace returns by channel reason code, channel reason text, or legacy reason.
- While viewing sales returns, when a user selects a store, the API shall filter by the channel shop identifier.
- The reason and store options shall be loaded dynamically from all marketplace return data and remain protected by the existing view-return permission.

## Architecture

- Frontend: `ReturChannelTab` loads filter options and sends `filter[reason]` and `filter[channel_shop_id]` with the existing paginated list request.
- Backend: `SalesReturnRepository` keeps Spatie Query Builder as the single filtering/sorting entry point; `SalesReturnController::filterOptions` exposes dynamic option metadata.
- Security: existing Sanctum and `view-retur-penjualan` middleware remain authoritative; filter values are validated by Spatie allow-lists and query bindings.

## Implementation Plan

- [ ] Extend allowed search and filters for list and unprocessed endpoints.
- [ ] Add dynamic reason and store options endpoint.
- [ ] Wire service, types, hooks, and UI controls.
- [ ] Add backend and frontend validation/build checks.
