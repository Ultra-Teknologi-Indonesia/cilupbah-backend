# Feature: Penjadwalan Pengiriman Instant/Same-day

## Requirements

- While a marketplace order is packed and identified as an instant/same-day order, when the user has permission to create shipments, the system shall allow the order to be placed into a scheduled shipment.
- While a user is viewing an eligible order detail, when they choose `Buat Pengiriman`, the system shall open the same shipment form used by the Shipping board.
- While a scheduled shipment is being created, the system shall reject cancelled, un-packed, duplicate, cross-location, courier-mismatched, or shipment-type-mismatched orders.
- When shipment creation succeeds, the order shall appear in the `Jadwal Pengiriman` tab and shall no longer remain in the `Siap Kirim` list.

## Architecture

### Frontend

- Reuse `BuatPengirimanDialog` and the existing shipment board query/mutation pattern.
- Allow two explicit modes: internal/manual shipment and marketplace instant shipment.
- Add a `Buat Pengiriman` action to the order detail header only for packed, non-cancelled, instant orders with the required permission.
- Keep loading, validation, and API error states in the existing dialog pattern.

### Backend

- Reuse `POST /api/v1/outbound/shipments` followed by `POST /api/v1/outbound/shipments/{id}/add-orders`.
- Keep the existing packed-order and cancellation guards.
- Add server-side validation that shipment type, courier, and warehouse location match the selected orders.
- Load the scheduled shipment summary on the order detail response for future idempotent UI handling.

### Security

- Keep `create-pengiriman` authorization on shipment creation and `view-pesanan` on order detail.
- Validate order IDs, warehouse IDs, shipment type, courier values, and dates server-side.
- Do not trust the frontend instant flag; classify using persisted shipping provider/type on the server.
- Record shipment activity with the authenticated actor through the existing order history mechanism.

## Performance and failure handling

- Use one bounded query for selected orders and a transaction with row locks for duplicate protection.
- Do not call external marketplace APIs while creating the local schedule.
- Keep the existing pickup/driver processing asynchronous after the transaction commits.
- A failed validation must not leave a partial shipment-order relation.

## Acceptance criteria

- A packed GoSend/Gojek/Grab/Same-day marketplace order can be scheduled from the Shipping board and from its order detail.
- A regular marketplace order cannot be scheduled through the instant flow.
- A scheduled order disappears from `Siap Kirim` and appears in `Jadwal Pengiriman` after refresh/invalidation.
- Duplicate clicks or concurrent requests do not attach an order to two shipments.
- Existing internal/manual shipment flow and regular courier flow remain unchanged.
