# Report Download Center — Implementation Design

## Flow

Report export trigger -> existing `ExportManager` -> `RunExportJob` on the
existing sheet/PDF/catalog queue -> private S3-compatible R2 storage -> `export_jobs`
metadata -> Download Report list endpoint -> authenticated artifact download.

The frontend submits an export and immediately shows a queued toast. It does
not wait for the binary or auto-download report exports. The existing shared
SSE stream emits terminal events; the Download Report query is explicitly
refetched when the event arrives, by the Refresh button, or when the page is
opened again. This flow does not use React Query invalidation.

## Backend boundaries

- `ExportJobRepository` owns user-scoped paginated queries and the allowlisted
  search/filter/sort implementation.
- `ExportJobService` coordinates repository access for the controller.
- `ExportJobResource` exposes only catalog metadata, safe error text, file
  metadata, and computed expiry; raw params, storage disk/path, and queue data
  are not exposed by the list/detail API.
- `ExportManager` remains the single export catalog/orchestrator.
- `RunExportJob` writes to a temporary file, persists the complete artifact,
  then marks the row ready and records its size.
- Cleanup remains idempotent, purges binary data only, and retains metadata.

## Safety and performance

- All list filters are validated against enums/allowlists; search is bounded to
  100 characters and uses escaped, case-insensitive prefix matching on
  `file_name`, plus catalog label/type and UUID prefix matching.
- `created_from` and `created_to` are calendar dates in WIB (`Asia/Jakarta`).
  The repository converts the start of each WIB day to UTC and uses an exclusive
  next-day boundary for `created_to`, so selected dates include 00:00:00 through
  23:59:59.999999 WIB.
- List queries are scoped by authenticated `user_id`, paginated in one query,
  and use user/date/status/type indexes. No per-row storage or status requests
  are made by the frontend.
- Download ownership is checked server-side and expired files return HTTP 410.
- Failed job messages are sanitized before being returned through REST or SSE.
- The `view-laporan-download` permission protects list, detail, and download
  endpoints, while existing export permissions remain responsible for each
  report trigger.
