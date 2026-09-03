# product catalog csv export

## objective

Provide a reliable asynchronous CSV export for the product master page. The export uses the same filters as the visible catalog and produces one row per visible variant (or bundle component), matching the catalog template supplied by the business.

## flow

```text
product master page
        |
        | POST /api/v1/products/master/export
        v
export_jobs (queued) ---> catalog-exports queue ---> dedicated worker
        ^                                             |
        | GET reports/exports/{id}                    | streaming query/chunks
        | GET reports/exports/{id}/download            v
        +---------------------------------------- CSV on documents disk
```

## decisions

- The request stores a normalized filter snapshot in `export_jobs`; the worker never reads the live HTTP request.
- Catalog jobs use `redis-long` and `catalog-exports`, separate from the general `exports` queue so a large catalog cannot delay other reports.
- The worker runs one job at a time, has a bounded PHP memory limit, a timeout, and automatic process recycling after one job.
- Laravel Excel receives a `FromQuery` export. Rows are read in chunks and mapped directly to CSV, so the full catalog is never collected in PHP memory.
- The query applies the master listing rules: requested status, hidden merge members excluded, technical bundle SKUs excluded, category descendants, type, channel, price, search, and selected product IDs.
- Stock is the canonical available stock used by inventory: placed stock in non-inbound bins minus `on_order`. Pending placement and inbound stock are not advertised as available.
- Download and ownership checks continue to use the existing `ExportJobController`.

## failure and safety

- Validation rejects unsupported filters, oversized selection lists, and invalid UUIDs before a job is created.
- A failed worker updates the existing export job to `failed`; the API returns the existing generic safe error message and never exposes SQL details to the client.
- A deployment must create the catalog worker from `k8s/production/03-catalog-export-worker.yaml` and set `QUEUE_NAME_CATALOG_EXPORTS` consistently if the default is changed.

## acceptance checks

- The API returns `202` and an export ID without generating the file synchronously.
- The dispatched job connection is `redis-long` and queue is `catalog-exports` by default.
- The generated header has the 18 columns in the supplied catalog CSV template.
- Zero-valued dimensions, prices, and stock are emitted as `0`, not blank.
- A large export completes without loading the complete result set into memory.
