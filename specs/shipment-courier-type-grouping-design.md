# Shipment Courier Type Grouping

## Requirements

- When a shipment report contains regular SPX shipments and instant/same-day SPX shipments, the system shall place them in separate groups.
- SPX regular shipments shall be reported as `SPX`.
- SPX shipments with `INSTANT` or `SAME_DAY` type shall be reported as `Instan/Sameday`.
- PDF and XLSX reports shall use the same grouping rule.
- Existing courier family grouping for other couriers shall remain unchanged.

## Architecture

- Backend: include the persisted `shipments.shipment_type` in report rows.
- Normalization: use the persisted shipment type as the authoritative discriminator before applying courier-family matching.
- Presentation: reuse the normalized group name in both summary and detail report builders; no frontend API contract change is required.

## Security and reliability

- Existing authenticated report routes and warehouse access constraints remain unchanged.
- Filters continue to use the existing validated query parameters and parameterized query builder.
- No user-controlled value is interpolated into SQL or rendered as trusted HTML.

## Implementation plan

- [x] Add shipment type to shipment report query rows.
- [x] Split instant/same-day shipments from regular courier families.
- [x] Cover summary and detail report grouping with regression tests.
- [x] Run focused and regression test suites.
