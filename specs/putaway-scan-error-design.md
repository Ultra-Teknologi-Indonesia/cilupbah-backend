# Putaway scan error and completion contract

## Goal

Keep the mobile putaway scanner consistent with the backend when a scanned SKU
is not eligible for another placement, without changing the existing process
endpoint or inventory movement semantics.

## Decisions

- The backend remains the final authority: a putaway cannot be completed while
  any item has remaining quantity, and a process request cannot mutate a
  completed item.
- Mobile matches a scan against all items in the current putaway, not only
  pending items.
- A matched item with zero remaining quantity is reported as already complete;
  an unmatched code is reported as not registered in this putaway.
- The mobile completion button is enabled only when every item has
  `putaway_qty >= qty`. A server-side guard remains mandatory for race safety.
- No optimistic inventory or progress mutation is performed by mobile; after a
  successful placement it reloads the putaway detail from the backend.

## Compatibility and safety

The API paths and payloads remain unchanged. The change only improves client
state selection and user-facing messages, while the existing backend guard
protects concurrent or stale clients.
