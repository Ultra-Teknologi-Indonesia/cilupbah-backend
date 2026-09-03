# Feature: Stock Chronology Impact Explanation

## Requirements (EARS)

- While an inventory movement is returned, when it represents an order reservation release, the API shall identify it as an availability change and explain that placed physical stock is unchanged.
- While an inventory movement restores physically picked stock, when it is returned, the API shall identify it as a physical restoration.
- While the stock-position history is displayed, when an impact explanation exists, the frontend shall show it beside the existing quantity and note without changing the quantity or running balance.
- While existing clients consume the movement response, when the additive explanation is absent or unknown, the frontend shall continue rendering the existing fields safely.

## Architecture

### Frontend

- Keep the existing `Qty` and `Saldo pada rak setelah aktivitas` values unchanged.
- Render a compact context label (`tersedia` or `fisik`) under the quantity for cancellation-related movements.
- Render the server-provided explanation in the existing keterangan cell.
- Add a short reading guide above the chronology so users understand that releasing a reservation can increase availability without increasing physical stock.

### Backend

- Add an additive `stock_effect` object to `InventoryMovementResource`.
- Derive the object from the fixed movement source vocabulary; no database write or migration is needed.
- Mark `ORDER_RELEASE` as `reservation_release` and physical restoration sources as `physical_restore`.

### Security

- The change is read-only and does not add an endpoint or accept new user input.
- Source matching uses fixed application constants; existing parameterized history filters remain unchanged.
- The response contains only stock movement context already authorized by the existing endpoint.

## Compatibility

- Existing response fields and their types remain unchanged.
- `stock_effect` is nullable and additive so older clients can ignore it.
- Existing quantities, balances, filters, pagination, and ledger records are not modified.

## Implementation Plan

- [x] Add the additive backend movement-impact contract.
- [x] Add frontend types and compact impact explanation.
- [x] Add the stock-position reading guide and accessible labels.
- [ ] Add backend regression coverage for reservation release and physical restore.
- [ ] Run backend and frontend validation.

## Acceptance Criteria

- A cancellation before picking displays `+1` with `tersedia` and explains that the reservation was released and physical stock did not change.
- A cancellation after picking displays a physical-restoration explanation.
- The historical balance remains the same value as before the change.
- No inventory movement, stock balance, or ledger row is created or changed by reading the page.
