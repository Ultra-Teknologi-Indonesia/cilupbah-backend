# Feature: Reliable Product Bulk Deletion

## Requirements

- While an authenticated user deletes multiple products, when any selected product fails a business precondition, the system shall delete none of the selected products and return the blocking products and reasons.
- While a bulk deletion is running, when an unexpected database or application error occurs, the system shall roll back all product changes and return a support reference instead of reporting a misleading partial success.
- When a bulk deletion is attempted, the system shall persist an audit record containing the actor snapshot, request reference, selected product snapshots, outcome, and failure context.
- When media cleanup fails after a successful database deletion, the system shall keep the deletion successful, log the cleanup failure, and leave the media eligible for retry.

## Architecture

### Frontend

- Keep the existing confirmation dialog and React Query mutation.
- Consume the atomic bulk result and invalidate the product list only after a successful deletion.
- Display business blockers and unexpected-error support references from the API response.
- Keep selection open when the operation is rejected so the user can correct the selection.

### Backend

- Add `product_delete_audits` as an immutable operational trace for each bulk request.
- Lock the selected active products inside one database transaction.
- Run all delete preconditions before the first write.
- Soft-delete all products and variants in the same transaction.
- Run external media cleanup after commit and make it non-fatal to the database operation.
- Catch and classify expected domain failures, database failures, and unexpected failures.

### Security

- Preserve the existing authenticated and permission-protected route.
- Validate UUID input server-side and de-duplicate selected IDs.
- Never expose exception messages, SQL, or storage details to the client.
- Record actor identity as a snapshot so the audit remains useful if the user is later deleted or renamed.
- Use parameterized Eloquent/query-builder operations only.

## Implementation Plan

- [ ] Add audit schema and model.
- [ ] Refactor product deletion into an atomic service operation.
- [ ] Add controller error classification and request/batch references.
- [ ] Improve frontend response and error presentation.
- [ ] Add regression tests for atomicity, business blockers, cleanup failures, and audit records.
- [ ] Run lint, tests, and build checks.
