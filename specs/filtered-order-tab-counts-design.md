# Filtered order tab counts

## Goal

Keep the count shown on every Pesanan tab equal to the total rows that the
corresponding tab would show after the active common filters are applied.

## Approach

- The client sends the same filter fields used by the order list to
  `GET /sales/counts`; pagination, sort, active tab, and sub-tab are omitted.
- The repository constructs one filtered base query, including search,
  warehouse access, and the existing allowed filters. Each tab count clones
  that query and applies the same tab visibility rules used by the list.
- Unfiltered counts keep the existing short-lived cache. Filtered counts are
  calculated per request so a mutation invalidation cannot return a cached
  count belonging to an unknown filter combination.

## Security

The existing Sanctum and `view-pesanan` middleware remain in place. The count
query continues to use the existing allowed-filter callbacks, Eloquent query
bindings, and `WarehouseAccess` scope; no client value is interpolated into
SQL.

## Verification

A feature test compares the filtered `ready-to-process` count with the list
pagination total and verifies that another channel is excluded.
