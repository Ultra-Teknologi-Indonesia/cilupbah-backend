# Stock adjustment import preview

## Scope

The stock-adjustment import preview must validate the same rack rules used when
stock is posted, while the browser must not receive or render hundreds of rows
at once. The confirmation path must be all-or-nothing for stock movements.

## API contract

- `POST /inventory/adjustments/import/preview` accepts optional `page`,
  `per_page` (1–100), `search`, and `sort` query parameters.
- `GET /inventory/adjustments/import/preview/{token}` returns another page from
  the actor-scoped, short-lived preview cache.
- `sort` is an allow-listed field, with a `-` prefix for descending order.
- The cache retains the complete validated input for confirmation; pagination
  only changes the response slice.

## Validation and consistency

- SKU existence, numeric quantities, bin ownership, negative-stock rules,
  occupancy rules, home-rack rules, and duplicate SKU/bin rows are checked
  before a row is returned as valid.
- Confirm rejects a preview containing errors and reuses the cached complete
  input, so a page change cannot change the applied data.
- Stock adjustment creation and movement processing run in one database
  transaction. A rack or stock-policy exception rolls back all movements and
  the document instead of leaving a partially applied adjustment.

## Security and performance

- Controller orchestration is kept thin; database reads and preview-cache access
  are isolated in `StockAdjustmentImportRepository` and
  `StockAdjustmentImportPreviewRepository`.
- Preview reads remain scoped to the authenticated actor through the existing
  cache actor id.
- Search and sort are performed on the bounded (maximum 1000-row) cached
  preview using an allow-list; no client-provided SQL fragments are used. The
  persisted-resource endpoints continue to use Spatie Query Builder with
  `allowedSearch`, `allowedFilters`, and `allowedSorts`; the transient preview
  cache intentionally uses the equivalent repository allow-list because it is
  not an Eloquent relation.
- Responses default to 25 rows and cap at 100, avoiding large browser payloads.
- Confirmation retains the existing permission middleware and does not expose
  access tokens or other sensitive data.
