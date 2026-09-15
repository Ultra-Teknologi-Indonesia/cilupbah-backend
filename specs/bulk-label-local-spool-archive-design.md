# Local-first shipping-label print with asynchronous archive

## Requirements

- The checker must receive the PDF from a local, low-latency spool instead of waiting for an R2 upload.
- Every ready PDF must still be archived to the configured object store and remain auditable.
- A local file may only be removed after the object-store upload has been verified.
- Archive work must not compete with marketplace/label work and must be bounded so it cannot exhaust memory or CPU.
- Existing batches and deployments must continue to work while a shared spool is being provisioned.

## Decision

Use a shared, durable local spool disk (PVC or equivalent) for the API and Horizon pods. The merge worker writes the final PDF to that disk and marks the batch ready immediately. A separate `label-archive` queue uploads the file to the archive disk using a stream, verifies the remote object size, and then removes the local file. If the shared spool is not configured, the service keeps the existing archive-first behaviour.

The printer acknowledgement is not treated as proof that paper was physically printed; browsers and printers cannot reliably provide that guarantee. The file is therefore archived as soon as it is safely spooled, without delaying the checker.

## State flow

`processing -> ready (spooled) -> archive_pending -> archive_processing -> archived`

Failures are retryable and retain the local file: `archive_processing -> archive_failed -> archive_processing`.

## Safety controls

- The archive job is unique per batch and uses a distributed lock.
- Upload uses a read stream; the complete PDF is never loaded again into the worker as a second copy.
- Local deletion happens only after remote existence and byte-size verification.
- The local spool must be a shared durable volume in Kubernetes; container-local ephemeral storage is not supported for production.
- The archive queue has one bounded worker by default, independent from the label queue.
- Legacy batches without a spool path continue to be served from the archive disk.
