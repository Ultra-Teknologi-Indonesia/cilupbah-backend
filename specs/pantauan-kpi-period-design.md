# Feature: Pantauan KPI Berdasarkan Periode

## Requirements (EARS)

- While the Pantauan page is loaded, when the monitoring endpoint responds, the
  system shall show the number of orders in the ready-to-process stage created
  today.
- While the Pantauan page is loaded, when the monitoring endpoint responds, the
  system shall show the ready-to-process orders from exactly two days ago as
  the comparison value.
- While monitoring data is loading or unavailable, the frontend shall show
  loading placeholders or a retry state and shall not display stale labels for
  a different metric.

## Architecture

### Frontend

- Reuse the existing period buckets returned by `/api/v1/outbound/orders/monitoring`.
- Use the day-0 and day-2-or-older `ready_to_process` values for the third KPI
  card.
- Rename the card to `Siap Diproses Hari Ini` and keep the comparison label
  `Pending Dari 2 Hari Lalu`.

### Backend

- Keep the existing authenticated monitoring endpoint and stage query.
- Add explicit summary fields derived from the same period aggregation:
  `ready_to_process_today` and `pending_from_two_days_ago`.
- Preserve the existing response fields for backward compatibility with older
  clients.

### Security and reliability

- The existing `view-pesanan` authorization middleware remains required.
- No user-controlled SQL or new write operation is introduced.
- The new fields reuse the already computed grouped stage query to avoid extra
  database scans.

## Implementation Plan

- [x] Confirm the mismatch between sales tab and monitoring stage scopes.
- [ ] Add explicit period-based KPI fields to the monitoring response.
- [ ] Update the frontend card labels and values.
- [ ] Add regression coverage for the period-based values.
- [ ] Run backend tests and frontend lint/test/build.
