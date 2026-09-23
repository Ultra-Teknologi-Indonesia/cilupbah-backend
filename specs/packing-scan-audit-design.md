# Feature: Packing Scan Audit

## Requirements

While a packlist is active, when a checker scans a product, the system shall increase that packlist item by exactly one unit and save one immutable scan event.

While a network request is retried, when it uses the same scan event ID, the system shall return the recorded result without increasing the quantity again.

While a packlist item is already complete, when another scan is received, the system shall reject the scan and leave the quantity unchanged.

## Architecture

- Frontend: creates one UUID per scan and keeps that UUID when retrying a request.
- Backend: receives only `scan_event_id`, locks the packlist item, increments the persisted quantity by one, and writes an audit row in the same transaction.
- Security: the existing `edit-packing` permission remains required; UUID input is validated server-side; audit rows contain only operational IDs and quantities.

## Implementation Plan

- [x] Add immutable pack scan audit storage.
- [x] Make packing scans idempotent and server-authoritative.
- [x] Send a stable scan event ID from both packing screens.
- [x] Add regression coverage for duplicate retries and over-scans.
