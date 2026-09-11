# Feature: Hard Cutoff Order Cutover

## Requirements

- While an operator starts a cutover, when a cutoff is entered in WIB, the system shall preserve only orders whose order timestamps are at or after that cutoff.
- While previewing, the system shall make no database mutations and shall report orders before the cutoff, orders kept after the cutoff, and blocking child/process relations.
- When applying, the system shall delete only pre-cutoff candidates that are safe to delete; a blocking relation shall stop a full apply unless partial mode is explicitly confirmed.
- The system shall not require or use legacy CSV whitelist files in the hard-cutoff UI flow.
- The system shall retain the existing CSV whitelist service path for backward compatibility with older API callers.

## Architecture

### Frontend

- Replace the primary order-cutover form with a `datetime-local` cutoff field.
- Explain that the cutoff is interpreted as Asia/Jakarta and that orders at or after the cutoff are retained.
- Keep loading, queue status, report, error, and explicit apply confirmation states.

### Backend

- `OrderCutoverConsoleController` accepts a manual WIB cutoff and creates a `hard_preview` job.
- `RunOrderCutoverConsoleJob` dispatches hard preview/apply jobs without materializing files.
- `OrderCutoverService` adds hard-cutoff preview/apply methods and a strict manual datetime parser.
- Candidate selection is parameterized and chunked; `sales_orders` before cutoff are candidates, while orders at or after cutoff are retained.
- Existing CSV whitelist methods remain available for compatibility, but are not used by the hard-cutoff UI.

### Security

- Preserve the existing token middleware, CSRF protection, route throttles, and explicit apply confirmation.
- Validate the datetime format server-side; client-side `datetime-local` validation is only a convenience.
- Keep partial deletion opt-in and require warehouse/order-sync acknowledgements.
- Keep report output limited to operational counts and samples already exposed by the console.

## Implementation Plan

- [x] Add hard-cutoff service preview/apply path.
- [x] Add manual cutoff validation and hard job types.
- [x] Update the order-cutover UI to the hard-cutoff flow.
- [x] Add feature coverage for preview, boundary retention, and apply.
- [x] Run targeted tests and formatting before delivery.
