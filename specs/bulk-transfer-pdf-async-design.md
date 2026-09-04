# Feature: Bulk PDF Export Asynchronous

## Requirements

- While a user has the relevant export permission, when the user selects any number of Putaway, Penyesuaian Stok, Picklist, Manifest, Faktur, or Transfer Keluar documents, the system shall accept the selection without the legacy synchronous limit.
- While an export is queued or processing, the system shall keep the HTTP request fast and shall not render the complete PDF inside the request process.
- When processing completes, the system shall provide one downloadable PDF containing the selected documents.
- When a selected document is no longer available or is outside the user's warehouse access, the request shall be rejected before creating an export job.

## Architecture

### Frontend

- Queue the bulk PDF through the authenticated API before opening the preview page.
- Open the preview in a new tab using the returned export job ID, avoiding a long URL containing every transfer ID.
- Reuse the existing export-job polling and download flow for queued, processing, ready, and failed states.

### Backend

- Add asynchronous routes for each bulk PDF document type:
  - `POST /api/v1/inventory/transfers/bulk/pdf/async`
  - `POST /api/v1/putaway/bulk/pdf/async`
  - `POST /api/v1/inventory/adjustments/documents/bulk/pdf/async`
  - `POST /api/v1/outbound/picklists/documents/bulk/pdf/async`
  - `POST /api/v1/outbound/shipments/documents/bulk/manifest-pdf/async`
  - `POST /api/v1/sales/invoices/bulk-pdf/async`
- Validate all IDs as UUIDs without the legacy `max:50` rule.
- Authorize the complete selection against the current user's accessible warehouses before queueing.
- Reuse `export_jobs` and `RunExportJob` with a dedicated type for each document.
- Render documents in bounded chunks using Dompdf, then merge each chunk with FPDI into the final file stored on the configured documents disk.
- Validate large selections in database-sized batches so the request does not exceed parameter limits.
- Keep the existing synchronous endpoints and their guards for compatibility with older API consumers; the frontend no longer uses them for bulk actions.

### Security and reliability

- Require the existing `export-barang-keluar` permission.
- Scope transfer IDs to the user's warehouse access before creating the job.
- Store only validated IDs in the owned export job payload.
- Use temporary files with cleanup in `finally` blocks and record failures through the existing export-job lifecycle.
- Do not mutate transfer, inventory, or movement data.

## Implementation Plan

- [x] Add asynchronous request and route.
- [x] Add access-scoped queue dispatch.
- [x] Add chunked transfer PDF worker service.
- [x] Connect export job lifecycle and filename handling.
- [x] Update frontend preview flow to use export job IDs.
- [x] Add regression coverage for selections over 50 documents.
