# Perbaikan Audit Modules/Warehouse

Semua temuan diverifikasi empiris (scratch test, sudah dihapus). Dokumen ini rencana eksekusi.

## P0 — Fitur rusak / 500 massal

| Kode | Masalah | Perbaikan |
|------|---------|-----------|
| W-1 | `PUT /locations/{id}` + `layout` selalu 500 (`column "layout" does not exist`) — key `layout` diteruskan ke `Location::where()->update($data)` | `LocationService::update` extract `layout` sebelum ke repository; cek lokasi exists dulu agar tidak false-404 saat hanya layout |
| W-2 | `{locationId}` bins/zones/default-bin tanpa `whereUuid` → 22P02 | `whereUuid('locationId')` di semua route turunan lokasi |
| W-3 | generate/preview non-uuid → 22P02 | sama (whereUuid) |
| W-4 | `PUT /locations/abc` + `location_code` → unique ignore inject id non-uuid → 22P02 | `whereUuid('location')` di apiResource (request ditolak 404 sebelum validasi) |
| W-5 | `POST /bins` location_id/zone_id non-uuid → 22P02 | `bail\|uuid` sebelum `exists:` |
| W-6 | `POST /channel-warehouses` location_id non-uuid → 22P02 | `bail\|uuid` location_id; `bail` channel_id |
| W-7 | `DELETE /channel-warehouses/abc` (PK bigint) → 22P02 | `whereNumber('id')` |
| W-8 | `?limit=abc` → TypeError (string ke `int $limit`) | hapus threading `limit`; repository pakai `paginate(request('per_page',10))->appends(...)` |
| W-9 | `max_qty=10.5` (kolom integer) → 22P02 | rule `integer` (bukan `numeric`) di Store/Generate bin request |
| W-10 | generate ke lokasi uuid-valid-tidak-ada → FK 23503 → 500 | cek lokasi exists di service → 404 |

## P1 — Integritas data / business flow

- **W-11** generate tidak idempoten & tanpa transaksi (dobel bin terkonfirmasi). → `firstOrCreate` per `(location_id,bin_final_code)`, bungkus transaksi, tambah **unique constraint** `location_bins(location_id,bin_final_code)` (migration dedupe+repoint referensi dulu).
- **W-12** hapus bin ber-stok hanya blokir bin inbound → stok kehilangan bin (nullOnDelete) atau 500 (inbound_receipts restrict). → blokir bila ada inventory `on_hand>0`/`reserved>0`; tangkap `QueryException` (FK restrict) → 422.
- **W-13** hapus zona via layout cascade hapus bin ber-stok diam-diam. → guard stok sebelum hapus zona (throw → 422).
- **W-14** hapus lokasi hanya cek `inventories`; FK restrict lain → 422 berisi SQL mentah. → tangkap `QueryException` → pesan manusiawi 422.
- **W-15** create channel-warehouse: duplikat → pesan generik. → `DomainException` 422 pesan jelas; lokasi-tidak-ada sudah dijaga validasi.

## P2 — Pelanggaran agents.md

- **§5** pagination: Location & ChannelWarehouse pakai `paginate($limit)` → ganti `paginate(request('per_page',10))->appends(request()->query())`.
- **§2** Resources: index/show/store/update kembalikan model mentah → pakai `LocationResource` (lengkapi zones/bins/channelWarehouses) & `ChannelWarehouseResource` (baru).
- **§1** arsitektur: `syncLayout` query Eloquent di Service → pindah ke `LocationZoneRepository`/`LocationBinRepository`; `LocationZoneController` inject repo langsung → lewat `LocationZoneService` (opsional kecil, dipertahankan minimal).
- Dead code: `LocationBinService::update`, import tak terpakai di `ChannelWarehouseController`.
- OA schema `ChannelWarehouse.id` salah (didok UUID, sebenarnya bigint) → perbaiki.

## Test pembuktian
`WarehouseNo500GuardTest` (semua jalur 500 → 404/422), `WarehouseBusinessFlowTest` (layout update, generate idempoten, guard stok bin/zona/lokasi).
