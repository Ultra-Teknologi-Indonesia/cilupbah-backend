# ADR: Protect Online Order Flow During Node Pressure

## Status

Accepted

## Context

The production cluster currently runs on one 16-core, 32 GiB node. Order
webhooks, stock propagation, AWB requests, reports, and imports share that
finite memory. Increasing every worker ceiling would turn a traffic spike into
an OOM event rather than increasing useful throughput.

Historical webhook inbox rows from before the server cutover must remain
auditable, but must not consume replay slots or repeatedly raise live alarms.

## Decision

- Apply Kubernetes priority classes: Redis/PgBouncer/scheduler are platform
  critical; HTTP and order/stock/webhook/AWB pools are online critical; reports
  and imports are batch priority.
- Keep the online pools warm and retain their memory-budgeted Horizon process
  ceilings. Batch pods are the first workloads Kubernetes may preempt when the
  node needs capacity for live orders.
- KEDA observes the Redis backend actually used by Laravel Horizon and keeps
  one background replica warm. It may grow only to three replicas on this node.
- Recycle dedicated export/import workers after 3,600 seconds or their existing
  max-jobs threshold, rather than restarting idle workers every 10–15 minutes.
- Ignore webhook replay and stale-event alerts before WEBHOOK_REPLAY_AFTER.
  This is a migration boundary, not a deletion.
- Replay new stuck webhooks every minute after two minutes, with a bounded
  batch and Redis backpressure. It is a safety net; normal webhook dispatch
  remains immediate.

## Alternatives considered

- **Raise every worker concurrency/replica count** — rejected: the measured
  aggregate pod memory limits already exceed physical RAM.
- **Delete historical inbox rows** — rejected: it loses audit evidence and
  removes the ability to reconcile migration data later.
- **Scale critical pools to zero or restart them before every deploy** —
  rejected: this introduces a preventable processing gap.

## Consequences

The system degrades predictably under pressure: reports/imports may wait or be
restarted, while accepted webhooks and stock/order operations retain priority.
This is not high availability: a single node still cannot survive host,
storage, or network failure. A multi-node cluster plus replicated Redis and a
tested database failover path remains required for a no-downtime claim.
