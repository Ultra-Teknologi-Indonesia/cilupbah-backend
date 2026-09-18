# Feature: Optimasi Payload dan Query Proses Pesanan

## Requirements

- While a user opens any Proses Pesanan stage or sub-tab, when the list API is
  requested, the backend shall return only fields rendered by that list and
  fields required by an available row action.
- While a user opens a fulfillment detail page, when the detail API is
  requested, the backend shall return only the detail header, rendered item
  fields, and fields required by an available action.
- When any list or detail response loads related data, the backend shall eager
  load or batch-load the relation in a bounded number of queries and shall not
  execute a query per row/item.
- Existing filters, sorting, pagination, warehouse scoping, permissions, and
  mutation behavior shall remain unchanged.

## Scope

Frontend routes covered by the existing stage tabs and sub-tabs:

- Pantauan and Pantauan drill-down.
- Picking: Belum Mulai, Diproses, Selesai, plus picklist detail/proses.
- Packing: Belum Mulai, Diproses, Selesai, plus packlist detail/proses.
- Shipping: Siap Kirim, Jadwal Pengiriman, Batal Pra-Manifest, plus shipment
  detail and memasukkan-ke-pengiriman.
- Sudah Dikirim and Selesai.

## Architecture

### Frontend

- Keep the existing query keys, filters, pagination, and mapping functions.
- Preserve the current TypeScript response contract while removing unused
  backend fields from JSON.
- No browser execution is required for verification; use static inspection,
  type checks/build, and backend feature tests.

### Backend

- Replace model passthrough resources with explicit list/detail resources.
- Add explicit select lists for list queries and relation select lists that
  include required foreign keys.
- Keep eager loading for displayed relations and use aggregate/subquery or
  batch queries for counts, totals, and bundle components.
- Add a dedicated pre-manifest cancellation list resource instead of returning
  a raw SalesOrder model.

### Security

- Preserve Sanctum authentication, permission middleware, and warehouse scope
  on every existing route/query.
- Exclude finance, raw channel payloads, internal notes, audit fields, and
  driver/credential fields from list resources unless the screen renders them.
- Do not add client-controlled columns or SQL interpolation; all filters remain
  through the existing validated QueryBuilder/parameterized Eloquent queries.

## Implementation Plan

- [x] Inspect all Proses Pesanan routes, tabs, sub-tabs, and API consumers.
- [ ] Add explicit resources for order, packlist, shipment, detail, and
      pre-manifest contracts.
- [ ] Narrow repository selects and eager-loaded relations without introducing
      per-row queries.
- [ ] Add regression tests for payload boundaries and query counts.
- [ ] Run focused backend tests and frontend type/build checks.

## Acceptance Criteria

- No list or detail endpoint in scope uses `parent::toArray()` for the response
  contract.
- No endpoint in scope serializes an entire Eloquent model as a list payload.
- Feature tests assert sensitive/unused fields are absent.
- Query-count tests cover the list paths and fail if a relation regresses to
  N+1 behavior.
