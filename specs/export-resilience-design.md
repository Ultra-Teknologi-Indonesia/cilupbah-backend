# Monitor Stock Export Resilience Design

## Scope

Prevent large Monitor Stok exports from affecting API availability, causing a
Cloudflare 502, or exhausting the Horizon pod memory budget.

## Decision

- Export jobs use a dedicated `exports` Redis queue.
- A dedicated Kubernetes worker consumes that queue with one process only.
- The worker exits after one job so PHP and PDF-library memory is fully
  reclaimed by Kubernetes between exports.
- Excel continues to use the query-based Laravel Excel writer, which reads the
  result in chunks rather than loading the complete dataset into PHP memory.
- PDF rendering is bounded to small row batches. Each batch is rendered by
  Dompdf and immediately merged into the final PDF with FPDI. Only the current
  batch and the final output are held by the process.
- The API pods never execute export work. Their health probes use `/healthz`, a
  static application health endpoint that does not execute dashboard queries.

## Resource policy

- At most one Monitor Stok export is actively rendered in production.
- The export worker has a 2 GiB hard memory limit and a 1.5 GiB PHP worker
  budget. This is a safety boundary, not a reason to increase concurrency.
- The main Horizon pod no longer consumes the export queue and receives modest
  headroom for its existing supervisors.
- A worker restart after every export limits the impact of gradual native or
  library memory growth.

## Failure handling

- Export jobs remain asynchronous and return a job id immediately.
- The job has a timeout below the Redis retry window and is marked failed by
  Laravel when it times out.
- Temporary PDF files are deleted in a `finally` block.
- The job writes the completed file to the configured document disk only after
  rendering succeeds, so a failed render cannot expose a partial download.

## Operational validation

Monitor these metrics after deployment:

- `cilupbah-export-worker` memory and restart count;
- export queue depth and job duration in Horizon/application logs;
- API pod readiness and endpoint count;
- API 5xx rate, CPU throttling, and PHP-FPM busy warnings;
- failed export jobs and temporary storage usage.

