# Feature: Effective Per-User Permissions

## Requirements

- While a user has one or more roles, when the user profile is returned, the API shall expose the user's final effective permissions in one flat `permissions` array.
- While an administrator edits a user, when the submitted permission list differs from the selected roles, the backend shall persist the difference without changing the role or other users.
- When a user is denied a permission inherited from a role, every backend authorization check shall reject that permission.
- When a user is granted a permission outside the selected roles, every backend authorization check shall allow that permission.
- The frontend shall not receive or calculate a separate `denied_permissions` or `direct_permissions` response field.

## Architecture

### Backend

- Spatie remains the source of role grants and direct user grants.
- `user_permission_overrides` stores only per-user deny records. Direct grants continue to use Spatie's `model_has_permissions` table.
- `User::hasPermissionTo()` applies a deny override before delegating to Spatie's normal role/direct permission check.
- `UserService` accepts the final requested permission list, computes role baseline versus requested permissions, syncs direct grants, and persists deny overrides in one transaction.
- `ProfileResource` exposes only the final effective `permissions` list for authorization consumers.

### Frontend

- The user form stores the final selected permission list and sends it as `permissions`.
- The permission matrix is editable per user; inherited role permissions are no longer locked because the user-level form is the override surface.
- Existing role management continues to manage role defaults independently.

### Security

- Owner accounts cannot be overridden.
- Permission names are validated against the permission catalog on the server.
- All writes remain behind the existing authenticated `edit-user` route guard.
- Backend enforcement is authoritative; frontend visibility is only a convenience.
- Permission changes are persisted transactionally and invalidate Spatie's permission cache through its normal sync APIs.

## API Contract

```json
{
  "roles": ["admin"],
  "permissions": ["export-pesanan"]
}
```

`permissions` means the final effective list for the user. The backend may store internal override rows, but those implementation details are not part of the API response.

## Acceptance Criteria

- A role permission can be removed for one user without changing the role.
- A permission can be added for one user without changing the role.
- Users sharing the same role remain unaffected.
- Direct API calls are denied when a permission is overridden off.
- Profile and user-management responses expose the same effective permission semantics.
- Existing clients that send `permissions` continue to work.
