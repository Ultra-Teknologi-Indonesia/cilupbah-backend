# Label fast path and bounded resource use

Status: bounded fast-path implementation and regression verification. Not deployed; no production capacity or latency guarantee. See ../docs/label-fast-path-rollout.md for delivered scope and remaining limitations.

## Acceptance criteria

- Background warming must not request shipment. Existing explicit staff fulfillment/driver actions remain unchanged; the modal remains an explicit AWB request, not a read-only screen.
- Reuse persisted package IDs for initial TikTok shipment; fetch details only for missing/uncertain package state. Never turn timeout verification into blind reshipping.
- Preserve package IDs when an order response omits packages; explicit package replacement remains authoritative.
- Apply TikTok chunking to actual package IDs, not only order counts. Lazada splits document requests at 20 packages and aggregates all chunks; missing/invalid chunks prevent completion. Batch sizes preserve conservative implementation limits, not assertions about unpublished channel quotas.
- Ready labels can be printed independently via immutable, owner-scoped snapshots. Snapshot creation never calls marketplace APIs or requests AWBs. Source work continues.
- Bounded snapshots copy ready artifacts under the same source finalization lock; PDF merge runs on the existing bounded merge lane. Snapshot retries reuse their own artifacts; cancelled/inaccessible orders are excluded.
- Report ready-file counts separately from tracking availability; refresh visible rows on coalesced progress events without invalidating unrelated queries.
- Never increase process counts without a measured memory budget. Keep critical order/stock lanes separate from download/render lanes.

## Implementation plan

1. Preserve package data and add cached TikTok preflight with read-only fallback tests.
2. Correct bounded API batching and prevent implicit background shipment.
3. Correct Shopee limiter admission and manual-lane visibility; keep existing rate ceilings.
4. Add immutable partial print snapshots with authorization, request deduplication, bounded count/bytes, and existing archive/retention paths.
5. Update modal progress and snapshot preview; retain shared UI components.
6. Verify affected tests, lint/types, deployment resource/timeouts, and document safe production canary and benchmark prerequisites.

## Security and failure modes

- Check authentication, batch ownership and current warehouse scope before selecting any snapshot items. Never accept arbitrary filesystem paths from clients.
- Recheck cancellation before shipping and inspect per-package errors; no operation relies on a label-read action as authorization to ship.
- A failed snapshot must not delete source artifacts or alter source status. Copy to child-owned paths; cleanup only those known paths on rollback.
- An expired/changed document requires explicit recovery, not shipment resubmission.
- Rate admission must be re-acquired after waiting. Keep waits bounded by a configured budget; fail visibly rather than bypass the rate ceiling.
- Snapshot printing includes all currently ready labels, including previously printed labels. It does not track physical printer success. No new shipment is requested by snapshot creation.
- Per-order cache writes use atomic shared spool files with a durable indexed archive outbox. Upload/checksum verification occurs in the bounded maintenance lane. Legacy/expired local cache reads may still require remote storage; pending archives are never purged.
- Stock ordering/mapping protections remain unchanged; increasing concurrency or caching live mapping checks is not authorized by performance targets alone.

## Measurement

## Follow-up: durable per-order cache and complete Lazada documents

- Backend: persist label/FPDI/thermal bytes on the shared print spool with an indexed database archive outbox. Queue payloads contain only an artifact ID. Upload and checksum verification run in the existing bounded label-archive lane; a scheduled bounded reconciler recovers dispatch failures and cleans only verified archived local files after retention.
- Lazada: split package document reads into at most 20 packages per request, combine all PDFs, and reject incomplete/invalid responses rather than returning a partial document. Apply byte/page/package bounds; do not invoke pack, RTS, ship, or driver calls from document aggregation.
- Frontend: keep existing label contracts and partial-ready modal; no new permission or client-side cache assumptions.
- Security: server-generated scoped paths only, atomic local writes, stream uploads, checksum verification, existing authenticated download endpoints. Never delete pending archives. No changes to inventory/order state rules or worker counts.
- Tests: R2/queue outage, duplicate archive, corrupt remote copy, local retention, cache hits for all channels, Lazada 20+20+1, pending chunk, invalid PDF, all pages retained, and affected regressions. Resource bounds are safeguards, not a zero-OOM guarantee.

Measure cold shipment, channel-ready-but-not-downloaded, cached labels, mixed readiness, repeated modal requests, cancellation, split packages, timeout/429, and concurrent stock/order traffic. Record p50/p95/p99 for queue wait, channel wait, download, render, merge and time-to-first-print, plus pod RSS/CPU/restarts. The existing isolated harness covers Shopee only. No live channel load test or deployment is performed automatically.
