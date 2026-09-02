# Active Process Orders Export

## Goal

Make the Export button on the Proses Pesanan board export only the active orders
represented by the current stage and sub-status. The export must not include
historical `Sudah Dikirim` or `Selesai` queues and must remain safe for large
datasets.

## Scope contract

The client sends the current board context to `POST /outbound/orders/export/async`.
The API accepts only these combinations:

| Stage | Sub-status |
| --- | --- |
| picking | belum, diproses, selesai |
| packing | belum, diproses, selesai |
| shipping | siap-kirim, jadwal, batal |

`pantauan`, `delivered`, and `done` are rejected because they are an overview or
historical views, not active process queues.

The server derives the database scope from this allowlist and never trusts a
client-provided query or status. The CSV row is also checked with the existing
process-status resolver before it is written, so a status transition during an
export cannot cause a row from another queue to leak into the file.

## Performance and safety

- The request only creates an export job and returns HTTP 202.
- The worker filters at the database level before reading rows.
- CSV output is streamed to a temporary file with `chunkById`; the full result
  set is never held in memory.
- Existing worker timeout, retry, private storage, cleanup, and memory limits
  remain in force.
- Invalid or historical scopes return validation errors before a job is created.

## Security

- The existing permission middleware remains required.
- Stage and sub-status are allowlisted server-side.
- No arbitrary SQL, column, sort, or filter expression is accepted from the
  export request.
- The export remains private and downloadable through the existing export-job
  authorization flow.

## Verification

Tests cover request validation, permission enforcement, job parameters, each
active scope, exclusion of historical orders, CSV encoding, channel shop names,
and private-storage worker output.
