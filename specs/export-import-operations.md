# Export/import production operations

## Runtime model

All large data exports are submitted as an `export_jobs` record and processed
asynchronously. The API returns `202` and an `export_id`; the frontend polls
the status endpoint and downloads only after the job is ready.

Queues are isolated by workload:

- `exports-pdf`: one PDF job per worker process, 1.5 GiB PHP limit, 2 GiB pod limit.
- `exports-sheet`: one XLSX/CSV job per worker process, 768 MiB PHP limit, 1 GiB pod limit.
- `catalog-exports`: one catalog CSV job per worker process, 512 MiB PHP limit, 1 GiB pod limit.
- `imports`: one import job per worker process, 1 GiB PHP limit, 1.5 GiB pod limit.

Every dedicated worker recycles after one job or its maximum runtime. This is
intentional: it bounds memory retained by PhpSpreadsheet/PDF libraries.

## Redis separation

Queue/Horizon Redis uses persistence and `noeviction`; cache/session Redis is
ephemeral and uses LRU eviction. Do not point cache/session at the queue Redis.
The queue PVC is sized at 5 GiB to leave room for AOF rewrite and operational
bursts. Before applying the manifest to an existing cluster, confirm that the
storage class supports PVC expansion; expand the claim through the storage
provider if required.

## Deployment order

1. Run database migrations, including the `export_jobs` queue-routing columns.
2. Deploy the application image.
3. Apply the cache Redis deployment and update the application secret with
   `REDIS_QUEUE_HOST`, `REDIS_CACHE_HOST`, `IMPORT_QUEUE_CONNECTION`, and the
   export queue names.
4. Apply the dedicated worker manifests.
5. Restart app, Horizon, and scheduler so cached configuration is refreshed.
6. Verify each deployment rollout and confirm no old worker still consumes
   `exports`, `product`, or a shared long-running queue unintentionally.

## Health checks

Use the following read-only checks after deployment:

```sh
kubectl get pods -n cilupbah
kubectl top pod -n cilupbah
kubectl logs -n cilupbah -l component=cilupbah-export-worker --since=30m
kubectl logs -n cilupbah -l app=cilupbah-pdf-export-worker --since=30m
kubectl logs -n cilupbah -l app=cilupbah-import-worker --since=30m
```

Check `export_jobs` for queued/processing/failed counts and inspect structured
`export.started`, `export.finished`, and `export.failed` events. A processing
job older than its worker timeout is an operational incident and should be
reconciled before retrying the request.

## Failure containment

Files are written to temporary storage only inside the worker and are removed
in a `finally` block. The final artifact is stored only after generation
completes. Failed jobs expose a safe user-facing message while the detailed
exception remains in server logs. Cleanup removes expired artifacts and rows
through `reports:cleanup-export-jobs`.

Do not raise PHP memory to `-1`, process unbounded rows with `get()`/`toArray()`
in a request, or add exports to Horizon's general-purpose supervisors.
