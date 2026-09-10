# Feature: Order cutover CSV latest-timestamp cutoff

## Requirements

- While four order CSV files are uploaded, when the preview starts, the system shall derive the cutoff from the latest valid order timestamp across all files.
- While the CSVs contain timestamps with gaps, the system shall keep every CSV order and every internal order at or after the derived cutoff.
- While the order cutover console is opened, when the user submits a preview, the system shall always scope orders to the official small warehouse (`O`) without accepting a user-supplied warehouse.
- When a CSV row has an order number but no valid order timestamp, the system shall block preview/apply instead of guessing a cutoff.

## Architecture

### Frontend

- Remove the editable cutoff and warehouse fields from the internal Blade form.
- Explain that cutoff is calculated from the latest timestamp in all four CSVs.
- Display the derived cutoff and timezone in the preview report.
- Keep explicit pause confirmations before apply.

### Backend

- `OrderCutoverService` parses supported order timestamp columns (`transaction_date`, `Tgl.Pesanan`, and safe fallbacks) using Asia/Jakarta.
- The service derives one immutable cutoff as the maximum timestamp across the union of all four CSVs.
- The controller persists the derived cutoff on the queued job and always persists location code `O`.
- Preview/apply retain CSV-matched orders plus orders where `created_at >= cutoff` or `transaction_date >= cutoff`.
- Deletion candidates must be older than the cutoff on both order timestamps, absent from the CSV whitelist, unprocessed by the warehouse, and free of blocking child relations.

### Security

- The existing token middleware and throttles remain mandatory.
- Client fields cannot override the warehouse scope or cutoff.
- Invalid/missing timestamps and non-small-warehouse rows are blocking validation errors.
- File hashes remain persisted and verified before the queued operation.
- Apply continues to require warehouse/order-sync pause confirmations and the explicit confirmation phrase.

## Implementation Plan

- [x] Add CSV timestamp parsing and latest-cutoff derivation in the service.
- [x] Remove cutoff/location inputs and force `O` in the console controller and view.
- [x] Persist the derived cutoff for deterministic preview/apply jobs.
- [x] Add tests for gaps, timestamp precision, and invalid timestamps.
- [x] Run targeted tests and lint; graph synchronization remains part of handoff.
