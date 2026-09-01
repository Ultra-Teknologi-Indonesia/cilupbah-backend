# Channel Product Download as New Import

## Scope

Channel search is evaluated per connected shop. A marketplace listing is
actionable as `download` when it has no usable mapping to an active master,
including when an old mapping points to a soft-deleted master. It is `none`
when the listing is already connected to an active master.

## Integrity controls

- Search and mutation routes remain behind Sanctum and the existing
  permissions.
- The server resolves the shop from the requested shop id and verifies that it
  belongs to the channel in the URL.
- A new download never matches a soft-deleted parent through a live variant
  SKU. It creates a new active master and mapping. If a live historical
  variant already blocks the marketplace SKU, the new master receives a unique
  internal SKU while the original marketplace SKU is preserved in the channel
  mapping.
- Existing `already_downloaded` remains in the response for compatibility.

## Acceptance criteria

- The same external listing can be `none` in one shop and `download` in another.
- A listing mapped to a deleted master is shown as `Download`, never
  `Pulihkan`.
- Downloading that listing does not restore the old product or its variants.
- Downloading that listing creates a new active master even when the old
  deleted parent still has live historical variants.
- Existing active-product download and channel search behavior remains intact.
