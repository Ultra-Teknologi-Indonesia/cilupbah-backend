# User deletion and historical retention

## Objective

Allow an authorized administrator to delete a non-owner user, reuse the same email for a new account, keep historical work and audit records readable, and release active assignments without changing warehouse or order business rules.

## Invariants

- The deleted identity is removed from `users`, tokens, roles, permissions, and location access.
- A new registration with the old email receives a new UUID and no relationship data from the old account.
- Historical records are never deleted only because their actor/assignee was deleted.
- Historical attribution uses immutable ID/name/email snapshots and no longer depends on the live `users` row.
- Active assignment pointers are cleared; the underlying inbound, putaway, picklist, packlist, and replenishment documents remain.
- An active inbound mobile session is closed as withdrawn so it cannot lock a document forever.
- All mutations run in one transaction. A future unexpected restrictive foreign key returns a business conflict (409), not a generic 500.

## Data strategy

The migration changes historical user references to `SET NULL` and adds immutable snapshots for user histories, login histories, inbound receipts, inbound participants, and inbound assignment history. Existing rows are backfilled before the constraints are changed. Ephemeral data such as access tokens and location membership is intentionally removed.

## Delete sequence

1. Lock and validate the target; reject self-delete and owner deletion.
2. Clear active assignment pointers and close active receiving participation.
3. Detach roles, permissions, locations, and tokens.
4. Insert the `deleted` user-history event with the target snapshot.
5. Delete the user row; database `SET NULL` constraints preserve dependent history.
6. Commit atomically or roll back everything.

## Acceptance criteria

- Deleting a user with inbound receipt history succeeds without HTTP 500.
- Inbound receipt, assignment, login, and account history remain after deletion.
- The old user ID can still be used to retrieve preserved user/login history.
- Re-registering the same email succeeds and the new account has no old roles, permissions, locations, or tokens.
- A deleted user's active work is visibly unassigned and documents remain available.
- Existing delete restrictions for self and owner remain unchanged.
