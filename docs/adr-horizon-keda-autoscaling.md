# ADR: Backlog-Based Pod Autoscaling for Non-Critical Horizon Pools

## Status

Accepted

## Context

Laravel Horizon can scale worker processes inside a pod, but its minimum
processes remain non-zero. The background and maintenance deployments therefore
retain PHP workers while their queues are empty. Production measurements showed
that this creates a significant idle-memory baseline even when the node has
ample headroom.

The order-intake, fulfillment, AWB, label, and stock paths must stay warm. A
cold start on those paths would trade memory savings for operational latency.

## Decision

Use KEDA Redis list-length triggers for only the non-critical `background` and
`maintenance` Horizon deployments:

- `minReplicaCount: 0` when their queues are empty;
- scale up from the Laravel Redis queue depth, not CPU alone;
- conservative cooldowns prevent pod flapping and exceed the longest job
  timeout before scale-to-zero, because a Redis ready-list trigger cannot see a
  job that is currently in the reserved set;
- maximum replicas remain bounded to protect PostgreSQL, Redis, and marketplace
  rate limits;
- critical operational pools remain outside KEDA and keep one warm pod.

The manifest is optional at cluster bootstrap. The production workflow detects
the KEDA CRD and applies the ScaledObjects only when the cluster supports them.
This keeps deployment safe before the platform team installs KEDA, while making
the missing capability visible in the deployment log.

## Alternatives considered

- **Scale every Horizon deployment to zero** — rejected because order, AWB,
  label, fulfillment, and stock would incur cold-start latency and could miss
  their response targets.
- **HPA on CPU/memory only** — rejected for queue workers because idle workers
  can use memory without processing backlog, while a queue can be delayed even
  when CPU is low.
- **A custom Laravel/Kubernetes scaler** — rejected because it duplicates a
  mature controller and adds another stateful control loop to the application.

## Operational requirements

1. Install and verify KEDA before enabling the optional manifest.
2. Monitor ScaledObject conditions, Redis queue depth, oldest job age, pod RSS,
   and failed jobs.
3. Keep KEDA `maxReplicaCount` below the tested marketplace and database budget.
4. Load-test each channel before raising thresholds or replica ceilings.

## Consequences

Positive:

- idle RAM from non-critical Horizon pods can return to the node;
- background work still starts automatically when a Redis list receives jobs;
- critical operational latency is preserved;
- scaling is driven by the actual source of work.

Negative:

- KEDA becomes a cluster prerequisite for full idle-memory optimization;
- a pod cold start remains for background work after a quiet period;
- Redis list-length triggers do not count delayed jobs until they become ready.
