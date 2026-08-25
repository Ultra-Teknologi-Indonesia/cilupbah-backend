# Feature: Export Persediaan Barang dan Persediaan Per Rak

## Requirements

- While an authenticated user has `export-laporan-persediaan`, when the user submits a stock report, the system shall queue an Excel export and return an export job id.
- While an export is processing, the system shall stream query results in chunks and shall not materialise the complete result set in application memory.
- When the report type is `by_rack`, the system shall require exactly one active warehouse location and shall reject the system Transit location.
- When the report type is `as_of_date`, the system shall calculate quantity from the latest inventory movement per item/location/bin up to the requested date.
- When an export finishes, the existing owned export-job endpoint shall expose its status and download securely for the requesting user.

## Architecture

### Frontend

- Add two report cards and dialogs to the inventory report page.
- Use the existing lazy multi-SKU picker, multi-location picker, and async export polling hook.
- Validate required date/location locally and present recoverable API errors without closing the dialog.

### Backend

- Add a discriminated `InventoryStockExportRequest`.
- Add `InventoryStockReportService` to own stock formulas and query construction.
- Add `InventoryStockReportExport` using `FromQuery`, explicit headings, mapping, and widths.
- Register `inventory-stock` and `inventory-rack` in `ExportManager` and queue them through `RunExportJob`.
- Keep the report scoped by `WarehouseAccess` and use parameterised query-builder bindings.

### Security and operations

- Require Sanctum and `owner|export-laporan-persediaan`.
- Validate UUID arrays, maximum filter sizes, dates, enum filters, and rack location eligibility on the server.
- Never allow a user to download another user's export job.
- Record the export through the existing export-job audit path.
- Use queue/chunked exports and explicit spreadsheet column widths to avoid OOM and excessive file size.

## Implementation plan

- [x] Audit current FE/BE report and export infrastructure.
- [ ] Implement validated backend request, service, export, manager registration, and route.
- [ ] Implement FE API service and report dialogs.
- [ ] Add feature/unit coverage for validation, Transit rejection, query filters, and export mapping.
- [ ] Run PHP lint/tests and FE production build.
