# Shipment instant type consistency

## Problem

Marketplace orders can expose a channel identifier such as `TIKTOK` in
`shipping_type` while the actual courier is `Gojek Instant`. The shipment
creation dialog used the channel identifier first, creating a `REGULAR`
shipment that could not accept the instant order.

## Design

- The frontend derives `shipment_type` from the actual courier name/provider.
- The backend normalizes an unambiguous instant courier to `INSTANT` as a
  defensive server-side guard.
- Existing add-order validation remains authoritative for instant/regular,
  location, and courier compatibility.
- No database migration or production data mutation is required.

## Acceptance criteria

- A shipment created for `Gojek Instant` is stored as `INSTANT` even if an
  outdated client sends `REGULAR`.
- A TikTok order whose `shipping_provider` is `Gojek Instant` can be added to
  the newly created shipment.
- Regular couriers continue to create regular shipments.
- Existing backend and frontend tests pass.
