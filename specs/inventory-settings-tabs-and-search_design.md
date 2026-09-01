# Feature: Consistent Case-Insensitive Search

## Requirements (EARS Format)

- While a user views `/dashboard/pengaturan/persediaan`, when the inventory sub-tab bar is rendered, the system shall display line-style tabs inside the same card treatment used by other dashboard components.
- While a user searches any local dashboard list, when the search term differs from stored values' casing, the system shall return matching records regardless of letter casing.

## Architecture

### Frontend

- Keep the existing controlled React tabs and product/sync views.
- Wrap the sub-tab list in `LiquidGlass`, with horizontal overflow support and a bottom border for the line-tab treatment.

### Backend

- Keep existing endpoint response/resource contracts.
- Use the shared `allowedSearch` implementation for standard list searches, and PostgreSQL `ILIKE` for local custom search clauses while retaining bound query parameters.

### Security

- Preserve the existing Sanctum authentication and `view-pengaturan-persediaan` authorization middleware.
- Keep the search value parameter-bound; no raw user input is interpolated into SQL.

## Implementation Plan

- [x] Update the inventory settings sub-tab presentation.
- [x] Make inventory settings product search case-insensitive.
- [x] Make remaining custom local searches case-insensitive.
- [x] Add regression coverage for mixed-case search.
- [x] Run focused backend and frontend checks.
