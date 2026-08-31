# Picking Reversal Order Reference

## Context

The stock-history screen shows `—` in `No. Ref` for a picking correction after a picklist is reverted or deleted. The correction movement remains in `inventory_movements`, but the picklist and its items no longer exist, so the history query cannot resolve the related sales order.

## Decision

Persist the order reference on every `PICKING_REVERSAL` movement at the moment the reversal is created. The history query uses this stored value first and keeps the existing relational lookup as a fallback for legacy movements.

The reference priority is:

1. `channel_order_no`
2. `no_ref`
3. `salesorder_no`

This preserves the current UI meaning of `No. Ref` while ensuring an internal order still has a useful reference when channel data is absent.

## Scope

- Add nullable `reference_number` to `inventory_movements`.
- Pass the order reference through all pick correction and picklist-reversal paths.
- Prefer the persisted reference in the stock-history projection.
- Keep the existing fallback for old movements and unrelated movement sources.
- No frontend contract change is required; the existing `reference_number` response field is reused.

## Data and performance

The value is written once with the movement, avoiding joins to deleted picklist rows during reads. Bulk unpick loads all relevant order references in one query to avoid an N+1 query pattern. The column is indexed for future filtering and operational diagnostics.

## Compatibility and rollout

The column is nullable so existing data remains valid. Existing historical corrections whose picklist relation has already been deleted may still have no resolvable reference; they must only be backfilled through a separately verified, auditable repair because the old movement does not contain a deterministic order identifier.

## Verification

- Service-level regression verifies `reversePick` persists the reference.
- History-level regression verifies the persisted reference is returned even without a picklist relation.
- PHP syntax, focused Inventory tests, and relevant Outbound tests are run before release.
