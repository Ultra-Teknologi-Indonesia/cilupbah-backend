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
- Use bounded per-queue recycle windows (catalog 10 minutes, sheet 12 minutes,
  PDF 15 minutes, import 29 minutes) plus max-jobs and memory guardrails. A
  bounded startup jitter prevents replicas from recycling together.
- Use RollingUpdate with `maxUnavailable: 0` for Horizon and dedicated CLI
  workers. Redis keeps pending jobs durable while the replacement starts, and
  startup/liveness probes are used only for Horizon masters; CLI workers rely
  on their PID and Laravel's timeout handling instead of a fake readiness probe.
- Use Redis blocking pops (`block_for=5`) so idle workers do not poll every few
  seconds and new bursts are consumed immediately.
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
