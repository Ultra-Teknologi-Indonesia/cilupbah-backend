# ADR: Adaptive Queue Capacity Without Operational Modes

## Status

Accepted

## Context

Production runs on one 16-core node with approximately 32 GiB memory. Order
intake, marketplace status changes, stock synchronization, AWB requests, label
downloads, exports, imports, and scheduled recovery must remain active without
an operator switching modes. Independent scaling and simultaneous deployment
surges can otherwise consume all schedulable memory even when actual memory
usage still looks low.

Laravel Horizon scales processes inside each pod. Kubernetes and KEDA scale the
number of pods. Both control loops must share a bounded capacity model so a
traffic spike cannot create `Pending` pods or displace another workload.

## Decision

Use one adaptive production mode with these rules:

- every operational queue pool keeps at least one warm pod;
- KEDA reads the ready-list backlog every five seconds and adds bounded replicas
  for order intake, fulfillment, stock, marketplace operations, and background
  work;
- AWB and label pools remain at one pod on the current single node and use
  Horizon process scaling inside that pod;
- maintenance remains warm and bounded at one pod;
- application replicas scale from three to four;
- exports and imports remain active with one pod each;
- maximum replica requests are limited to 25,600 MiB, leaving at least twenty
  percent of the modeled 32,000 MiB node capacity uncommitted;
- production workloads roll out one at a time after a capacity preflight;
- no active worker is scaled to zero during deployment;
- KEDA is a mandatory production dependency.

Queue delays and marketplace rate limits remain part of the application flow.
Adding pods does not bypass marketplace quotas or cause repeated API calls.

## Alternatives considered

- Manual normal and flash-sale modes were rejected because they depend on an
  operator acting at the correct time and create configuration drift.
- Scaling all pools to zero was rejected because the first critical job would
  pay pod startup latency.
- Scaling every heavy pool to two pods was rejected on the current node because
  the worst-case request budget would exceed the safe scheduling envelope.
- CPU-only scaling was rejected because queue depth and oldest-job age are the
  direct indicators of pending work.

## Consequences

The system reacts automatically while preserving a warm path for every type of
work. The maximum possible scheduled load fits the current node model, and
deployments cannot create all rollout surges at once. Increasing replica caps
or adding new workers requires updating the capacity test and repeating the
load test on the target infrastructure.
