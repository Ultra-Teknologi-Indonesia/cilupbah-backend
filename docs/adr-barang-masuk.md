# ADR — Modul Barang Masuk (Inbound)

> **Status**: Proposed — menunggu sign-off.
> **Tanggal**: 2026-06-25.
> **Konteks**: blocker Fase 0 di `PLANNING-PESANAN-MASUK.MD` §7. Tiga keputusan ini memengaruhi migrasi DB & kontrak FE, jadi harus dikunci sebelum Fase 2.
> **Konvensi status**: Proposed → Accepted (setelah sign-off) → Superseded (jika diganti ADR lain).

Daftar keputusan:

- [ADR-001 — Sumber kebenaran Putaway](#adr-001--sumber-kebenaran-putaway)
- [ADR-002 — Semantik PO `receive`](#adr-002--semantik-po-receive)
- [ADR-003 — Serial Number / Batch / Expired](#adr-003--serial-number--batch--expired)

---

## ADR-001 — Sumber kebenaran Putaway

**Status**: Proposed

### Konteks
Saat ini ada **dua mekanisme putaway paralel** yang tidak tersinkron:

- **(A) Inbound-centric** — `inbound_items.putaway_qty` digerakkan langsung oleh `InventoryService::putaway` (jalur `processPutaway` / `autoPutaway` / `scanPutaway` di `InboundService`). **Inilah yang benar-benar menaikkan stok rak.**
- **(B) Putaway-centric** — model `Inventory\Putaway` + `putaway_items` (punya `putaway_no` `PUT-`, `assigned_to`, status `NOT_STARTED/IN_PROGRESS/COMPLETED`, `PutawayService::start/processItem/complete`). Dibuat oleh `InboundService::createPutawayFromInbound` tapi **tidak menggerakkan stok** dan **tidak tersinkron** dengan (A).

Akibatnya, jika tab "Penempatan Barang" dibangun dari (B), progress bar akan bohong karena qty nyata ada di (A).

### Keputusan (rekomendasi)
**Opsi A — Inbound-centric** untuk MVP.

- Tab Penempatan dibangun dari `inbounds` / `inbound_items` (`putaway_qty` vs `received_qty`).
- `createPutawayFromInbound` dan model `Inventory\Putaway` **dihentikan dari alur inbound** (tidak lagi dibuat saat receiving). Boleh disisakan sebagai entitas terpisah bila dipakai modul lain, tapi bukan sumber kebenaran tab Penempatan.
- Kolom display tab Penempatan dipetakan: `No. Penempatan` = `transaction_number` inbound (atau nomor turunan), `Dibuat Oleh` = `inbound.created_by`, `Dikerjakan Oleh` = `inbound_assignments.assigned_to`, progress = `Σ putaway_qty / Σ received_qty`.

### Alasan
- Jalur (A) sudah teruji menggerakkan stok; memilih (A) menghindari refactor jalur stok yang berisiko.
- Satu sumber kebenaran → progress akurat, lebih sedikit kode untuk dijaga.
- Assignment (`inbound_assignments`) sudah menyediakan "Dibuat Oleh vs Dikerjakan Oleh" tanpa butuh model Putaway.

### Konsekuensi
- (+) FE Fase 1/3 cukup memanggil endpoint `inbounds`; tidak ada dua sumber data untuk satu tab.
- (+) Tidak perlu menjaga sinkronisasi A↔B.
- (−) No. `PUT-` terpisah tidak ada secara native; bila benar-benar dibutuhkan, turunkan dari inbound (lihat ADR penomoran di planning §9 Fase 0).
- (−) Bila nanti butuh workflow putaway kaya (multi-assignee per dokumen, dsb.), perlu revisit ke Opsi B (tandai sebagai Superseded).

### Ditolak
- **Opsi B — Putaway-centric**: lebih sesuai screenshot sistem lama (`PUT-`, assignee per putaway) tapi menuntut `InboundService` berhenti menaikkan stok dan mendelegasi ke `PutawayService` — refactor jalur stok yang mahal & berisiko untuk MVP.

---

## ADR-002 — Semantik PO `receive`

**Status**: Proposed

### Konteks
`PurchaseOrderService::receive()` saat ini, dalam satu transaksi:
1. menaikkan `po.received_qty` (`updateItemReceivedQty`),
2. mengurangi on-order (`adjustOnOrder(-qty)`),
3. set status PO `PARTIAL_RECEIVED` / `FULLY_RECEIVED`,
4. membuat Inbound berstatus **DRAFT** dengan `expected_qty = qty yang diinput`.

Masalah: progress PO naik **sebelum** konfirmasi fisik di gudang. Gudang masih harus `receive` lagi di Inbound untuk membukukan stok. Bila qty fisik ternyata kurang (discrepancy), progress PO sudah terlanjur menghitung qty penuh → **double-count / progress bohong**.

### Keputusan (rekomendasi)
**Opsi A — PO `receive` hanya membuka Inbound; progress PO mengikuti qty fisik.**

- `PO.receive` **tidak** lagi menaikkan `po.received_qty` saat membuka Inbound. Ia hanya membuat dokumen Inbound (DRAFT) sebagai "rencana penerimaan".
- `po.received_qty` (dan status PARTIAL/FULLY_RECEIVED) di-update **saat `InboundService::receive` fisik selesai**, lewat callback/event dari Inbound ke Purchase (mis. event `InboundReceived` yang di-listen Purchase untuk sinkron progress source).
- On-order (`adjustOnOrder`) ikut bergerak pada titik fisik, bukan pada titik buka Inbound.

### Alasan
- Sesuai alur kanonik sistem lama: "Terima" di tab PO membuka form, qty fisik yang diisi itulah yang meng-update progress PO.
- Menghapus jendela inkonsistensi antara "PO bilang diterima" vs "stok belum ada".
- Pola event source↔inbound bisa dipakai seragam untuk Transfer & Sales Return (progress source selalu cermin penerimaan fisik).

### Konsekuensi
- (+) Progress PO/Transfer/Retur selalu = qty fisik; discrepancy tertangani benar.
- (+) Satu pola sinkronisasi untuk ketiga sumber.
- (−) Perlu event/listener baru `Inbound → source` dan memindah update `received_qty` keluar dari `PO.receive`. Sentuh `PurchaseOrderService`, `InboundService`, dan listener Transfer/Sales.
- (−) Butuh test regresi untuk partial receive lintas modul.

### Ditolak
- **Opsi B — pertahankan perilaku, pisah kolom `qty_planned_receive` vs `qty_received_physical`**: menambah kolom & query, tapi tetap menyimpan dua angka untuk satu konsep — lebih membingungkan daripada memindah update ke titik fisik.

---

## ADR-003 — Serial Number / Batch / Expired

**Status**: Proposed

### Konteks
Hard rule bisnis (planning §8 #5–#6, kode error sistem lama P9002/P9006):
- Batch Number **wajib** disertai `exp_date`.
- Serial Number **unik per SKU**.

Kondisi kode: `inbound_receipts` punya `batch_no` & `serial_no` sebagai string bebas, **tanpa `exp_date`** dan **tanpa** validasi keunikan SN. `ReceiveInboundRequest` hanya `nullable string`.

### Keputusan (rekomendasi)
**Masuk MVP (Fase 2), dengan validasi keras.**

Skema:
- Tambah kolom `exp_date DATE NULL` di `inbound_receipts` dan `putaway_items`.
- Keunikan SN per SKU: tabel `inventory_serials (item_id, serial_no, status, location_id, bin_id, ...)` dengan unique `(item_id, serial_no)` yang **aktif**, atau minimal unique index parsial untuk SN yang sedang "in stock". (Pilih tabel terpisah agar bisa melacak siklus hidup SN inbound→outbound nanti.)

Validasi (`ReceiveInboundRequest` + service):
- `qty_sekarang ≤ expected_qty − received_qty` (sudah ada di service, pindahkan/duplikasi sebagian ke request bila perlu).
- Jika item flagged Batch → `exp_date` **required**.
- Jika item flagged Serial → `serial_no` **required & unik per SKU**; satu SN = qty 1.

### Alasan
- Hard rule; jika ditunda, form penerimaan harus dirombak ulang setelah rilis (mahal). Lebih murah sekalian di Fase 2 saat form Terima dibangun.
- Tabel `inventory_serials` terpisah membuka jalan FEFO/serial-tracking outbound (backlog) tanpa migrasi besar lagi.

### Konsekuensi
- (+) Form Terima sekali jadi, tidak rework.
- (+) Audit SN/Batch siap untuk fase outbound.
- (−) Menambah migrasi + flag SN/Batch per SKU di katalog (cek apakah `product_variants` sudah punya toggle `track_serial`/`track_batch`; jika belum, tambah).
- (−) Validasi keunikan SN butuh penanganan konkurensi (lock saat insert SN).

### Ditolak
- **Tunda ke pasca-MVP**: menyebabkan rework form Terima & migrasi data penerimaan yang sudah terlanjur tanpa exp_date/SN.

---

## Tindak lanjut setelah Accepted
- ADR-001 → Fase 1 & 3 (FE tab Penempatan dari `inbounds`; pensiunkan `createPutawayFromInbound`).
- ADR-002 → Fase 2 (event `InboundReceived` + pindah update `received_qty`).
- ADR-003 → Fase 2 (migrasi `exp_date` + `inventory_serials` + flag katalog + validasi).
