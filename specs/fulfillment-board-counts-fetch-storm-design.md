# Feature: Fulfillment Board Counts and Fetch-Storm Prevention

## Requirements

- While a user opens any `/dashboard/proses-pesanan/*` list page, the frontend shall request board counts through one authenticated API request.
- While a user is viewing a stage without count tabs, the frontend shall not request board counts.
- When a stage navigation link is rendered, the frontend shall not prefetch another fulfillment page unless the user activates it.
- The count response shall be scoped by the existing warehouse-access policy and shall not expose data outside the authenticated user's scope.

## Architecture

### Frontend

- Add `GET /api/v1/outbound/orders/counts` as one React Query resource.
- Enable the resource only for Picking, Packing, and Shipping stages.
- Disable Next.js prefetching on the fulfillment stage tab links.
- Keep list/detail polling isolated to the pages that explicitly need live updates.
- Use a shared query key and a 30-second stale window to prevent duplicate observers from issuing duplicate requests.

### Backend

- Add an authenticated, `view-pesanan`-protected `GET /api/v1/outbound/orders/counts` endpoint.
- Return the complete count map in the existing `{status, title, message, data}` envelope:

```json
{
  "picking": {"belum": 0, "diproses": 0, "selesai": 0},
  "packing": {"belum": 0, "diproses": 0, "selesai": 0},
  "shipping": {"siap-kirim": 0, "jadwal": 0, "batal": 0}
}
```

- Reuse the existing fulfillment stage predicates and warehouse scope. Shared stages are calculated once per request and reused in the response.

### Security and reliability

- Sanctum authentication and `role_or_permission:owner|view-pesanan` remain mandatory.
- No request-controlled stage, SQL fragment, or raw filter is accepted by the aggregate endpoint.
- Count queries use Eloquent builders and the existing `WarehouseAccess` scope.
- No business data is mutated by the endpoint.

## Implementation Plan

- [x] Add the aggregate backend endpoint and OpenAPI metadata.
- [x] Add the typed frontend service and query hook.
- [x] Gate count loading by active stage and disable stage-link prefetch.
- [x] Align Picking server/client hydration parameters.
- [x] Restrict generic query retries to transient failures.
- [x] Add backend contract/auth regression coverage.
