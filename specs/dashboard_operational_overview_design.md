# Dashboard operational overview

## Goal

Make the dashboard a concise operational starting point: users see the five newest items in each action queue and the current health of connected omnichannel stores without opening the integration page first.

## Experience

- The action queues show at most five newest orders. The complete queue remains available through **Lihat semua**.
- Operational-health cards use a balanced three-column desktop grid, so the six cards do not leave an unused half-row.
- The integration card shows the total connected stores, aggregate health, and at most five stores. Stores needing attention come first.
- The integration card links to the existing integration-management page; it does not duplicate its controls.

## API and data

`GET /api/v1/dashboard/summary` gains an `integration` object containing aggregate counts and a maximum of five display-safe stores.

The response deliberately excludes credentials, token expiry timestamps, raw provider errors, and reauthorization actions. Those details remain behind the existing integration permission on the integration page. Dashboard users receive only the status, shop/channel identity, and last successful integration timestamp.

## Security and performance

- Dashboard data remains scoped by its current authorization middleware.
- The integration overview is included in the existing 60-second dashboard-summary cache.
- One bounded query loads connected shops and their channel names; no per-shop queries are issued.
- The five displayed stores are ordered by severity (`error`, `warning`, `inactive`, `normal`) and then by most recent sync.

## Verification

- Feature contract test verifies that the integration overview is included and no financial dashboard fields are introduced.
- Frontend lint and production build verify the compact queue and integration card render safely.
