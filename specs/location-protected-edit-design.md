# Protected Location Edit Design

## Requirement

Users with `edit-manajemen-rak` may edit the location details, layout, and zones even when a location is system-managed or locked. A protected location must remain active and cannot be deleted. Users without the edit permission remain unable to edit it.

## Contract

- `is_system` and `is_locked` are protection flags, not read-only flags.
- The update endpoint continues to require `edit-manajemen-rak` through route middleware.
- For a protected location, an update that sets `is_active` to `false` is rejected server-side with HTTP 422.
- Other validated location fields may be updated normally.
- Delete remains rejected for both system and locked locations.
- The FE disables only the active switch for protected locations and keeps the remaining edit controls enabled for authorized users.

## Compatibility and safety

- The existing endpoint and payload shape are retained.
- The FE omits `is_active` when saving a protected location so an existing inactive legacy record is not accidentally changed or rejected while editing another field.
- Layout, bin, and zone operations continue to use their existing permission checks; no client-side lock state is trusted for authorization.
- Existing stock and transaction guards remain unchanged.

## Acceptance criteria

1. An authorized user can open `/dashboard/lokasi/{id}` and see an Edit action for a protected location.
2. On the edit screen, authorized users can edit all existing location form fields and layout/zona controls.
3. The active switch is disabled for a protected location.
4. Direct API update cannot deactivate a protected location.
5. Direct API delete cannot remove a protected location.
6. Users without the edit permission cannot edit through FE or API.
