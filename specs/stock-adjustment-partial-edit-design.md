# Feature: Partial Stock Adjustment Edit

## Requirements

While an adjustment document contains many item rows, when an authorised user
saves edits from one or more pages, the system shall persist only created,
changed, and deleted rows without requiring the browser to download every row.

## Architecture

### Frontend

- Keeps a small client-side change set for the rows the user has actually touched.
- Uses paginated reads (20 by default) and sends one `PATCH` request on save.
- Shows the API validation message without discarding the current draft.

### Backend

- Adds `PATCH /inventory/adjustments/documents/{id}` alongside the legacy full
  replacement `PUT` endpoint.
- Locks the document and only the affected adjustment rows and inventory rows.
- Validates bin ownership, bundle restrictions, and final SKU/bin uniqueness
  against the complete server-side document before any mutation.
- Reverses and reapplies only rows whose stock effect changed; unchanged rows
  and their movements remain untouched.

### Security

- Existing `edit-penyesuaian-stok` route permission and warehouse access scope
  remain mandatory.
- Laravel request validation rejects unknown row identifiers and invalid bins.
- All changes run in one database transaction; a rejected row rolls back the
  complete patch.

## Implementation Plan

- [x] Define partial-edit request contract and server-side validation.
- [x] Add scoped partial mutation endpoint.
- [x] Replace browser-wide page fetching with change-set submission.
- [x] Test that an untouched row survives a partial update.
