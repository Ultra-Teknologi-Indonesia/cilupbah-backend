# Feature: Global Server-side Sorting and Date Filters

## Audit scope

The audit covers dashboard tables that use server pagination and expose a sortable column. The current shared table component sorts locally unless `manualSorting` is enabled, so a sortable table without that flag only reorders the current page.

## Audit findings

| Area | Current state | Gap |
| --- | --- | --- |
| Pesanan, Produk, Transfer Keluar, Retur Channel | Server-side sorting already wired | Keep existing pattern |
| Picking, Packing, Shipping | Server-side sorting already wired | Keep existing pattern |
| Penerimaan Barang | Server-side sorting and date range | Keep existing pattern |
| Penempatan Barang | Server-side sorting and date range | Keep existing pattern |
| Penyesuaian Stok | Server-side sorting and date range | Keep existing pattern |
| Stok Opname | Server-side sorting and date range | Keep existing pattern |
| Cadang Stok | Server-side sorting and date range | Keep existing pattern |
| Revaluasi | Server-side sorting and date range | Keep existing pattern |
| Pindah Bin | Server-side sorting | Keep existing pattern |
| Pesanan Pembelian | Server-side sorting | Keep existing pattern |
| Posisi Stok | Sortable quantity/cost columns needed computed server sort keys | Compute sort keys in the paginated query; never reorder the page in the browser |
| Detail/subtable views | Persisted detail tables already use server pagination and sorting | Keep existing endpoint contract; only unsaved local layout drafts may remain local |

## Requirements

- While a paginated table exposes sorting, when a user clicks a sortable header, the API shall receive a validated sort field and direction and return the correctly ordered full result set before pagination.
- While a list has a business date field, when a user selects a date range, the API shall filter the full result set before pagination.
- When a user changes sorting or date filters, the list shall reset to page one and preserve state in the URL namespace.
- Unsupported sort fields shall be rejected by the backend query builder rather than interpolated into SQL.

## Architecture

### Frontend

- Reuse `useListState` for URL state, page reset, and one-column sorting.
- Mark server-paginated `DataTable` instances with `manualSorting` and pass `sorting`/`onSortingChange`.
- Reuse `DateRangePicker` and the existing filter toolbar pattern.
- Send only scalar filter/sort values; do not load all rows into the browser.

### Backend

- Extend existing `Spatie\QueryBuilder` allowed sorts and filters on the affected repositories.
- Use explicit date fields per resource: inbound `created_at`, putaway `created_at`, revaluation/opname/reservation `created_at`, and existing transaction/transfer/order dates where already supported.
- Keep default sorts and stable tie-breakers where currently defined.

### Security and performance

- `allowedSorts` remains the server-side allowlist, preventing arbitrary SQL order expressions.
- Date values are parsed through existing request/query-builder validation; no raw client SQL is accepted.
- Filters and sorting execute before `paginate`, so memory use remains bounded by the requested page size.
- Existing warehouse access scopes remain applied to every affected query.
- Computed stock-position sort keys are calculated only when requested, so normal list requests do not pay the extra aggregate cost.

## Acceptance criteria

- Clicking a sortable header changes ordering across all pages, not just the visible page.
- Sorting survives refresh through the page's URL namespace and resets pagination to page one.
- Date range filters are available on the affected list filters and apply before pagination.
- Existing server-side sortable pages remain behaviorally unchanged.
- No affected endpoint returns an unsupported sort field or performs an unbounded browser-side sort.
- Posisi Stok quantity and average-cost ordering is applied before pagination, including mixed variant and bundle results.
