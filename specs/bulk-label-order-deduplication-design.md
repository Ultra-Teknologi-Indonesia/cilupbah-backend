# Bulk shipping label: order-level deduplication

## Decision

An order is the idempotency boundary for shipping-label work. A batch is only
a presentation and merge boundary; it is not allowed to create a second
channel fetch for an order already being processed by another batch.

The implementation uses four safeguards:

1. Duplicate order IDs are removed during batch creation and rejected by the
   API validator.
2. Item jobs use an order-level unique key and a distributed order lock.
3. A successful label is cached once in the durable order cache and fanned out
   to every transient item for that order across all batches.
4. A new batch hydrates from that cache before dispatching queue work, so a
   repeated order does not start from fetch/queue again.

AWB acquisition has its own `label-awb` queue and order/attempt idempotency
key. Six bounded label workers process downloads, two workers archive local
artifacts, and AWB is capped at two workers because it calls channel
fulfillment APIs and is rate-sensitive. The application-side per-channel
limiter remains the throughput ceiling; adding workers must not bypass it.

## State flow

```text
batch request
    |
    +--> cached label exists --> mark all order references DONE --> merge
    |
    +--> no cache --> one order job/lock --> channel fetch once
                                      |
                                      +--> cache artifact
                                      +--> fan out DONE to all batches
                                      +--> each batch merges independently
```

## Local-first storage

The local spool is enabled only with the shared durable `cilupbah-label-spool`
PVC mounted by both the API and label Horizon pods. The archive worker uploads
and verifies the object before deleting the local spool file. If the spool is
unavailable, the existing service falls back to archive-first and logs the
condition, preserving correctness while making the storage problem visible.

## Operational guardrails

- Label workers are isolated in the `labels` Horizon profile; background work
  cannot consume their queue.
- The deployment has six bounded label processes, a 3.5 CPU limit, and a 6 GiB
  memory limit.
- The AWB and archive queues remain separate from the six download workers.
- The deployment must be observed through queue depth, active database
  connections, error rate, and pod restarts after rollout.
