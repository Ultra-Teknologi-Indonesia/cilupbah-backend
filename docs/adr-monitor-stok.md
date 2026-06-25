# ADR — Halaman Monitor Stok

> **Status**: Accepted (2026-06-25). Mengeksekusi Fase 0–2 `PLANNING-MONITOR-STOK.MD`.
> **Konteks**: dashboard 7 tab read-only di atas Inventory/Product/Purchase/Sales/Channel. Keputusan ini mengunci kontrak BE untuk Fase 1–2 (tab Stok Kosong, Menipis, Sedang Dibeli) dan menyiapkan fondasi Fase 3 (analitik penjualan).

Daftar:
- [ADR-201 — Layer analitik penjualan: materialized vs on-the-fly](#adr-201)
- [ADR-202 — Dua ambang (`min_stock`/`safe_stock`) + `is_stored` + skup lokasi](#adr-202)
- [ADR-203 — Default threshold tab analitik](#adr-203)
- [ADR-204 — Sumber data "Sedang Dibeli" (On Order)](#adr-204)
- [ADR-205 — Gagal Sync: sumber & retry](#adr-205)

---

## ADR-201 — Layer analitik penjualan: materialized vs on-the-fly
**Keputusan (revisi saat implementasi Fase 3, 2026-06-25)**: Fase 3 (Tidak Laku/Paling Laku/Perkiraan Habis) dirilis dengan **agregasi on-the-fly** langsung dari `inventory_movements` (`source = ORDER_SHIP`), didukung index `inv_movements_source_date_item_idx (source, transaction_date, item_id)` dari Fase 0. Tabel materialized `item_sales_daily` **ditunda** sampai terbukti perlu (perf pada volume produksi).
**Alasan**: on-the-fly selalu konsisten (tanpa lag sinkronisasi), tanpa kompleksitas backfill + observer/job; index membuat `MAX(transaction_date)` dan `SUM(ABS(qty))` per `item_id` cukup cepat. Materialized adalah optimasi yang baru bernilai saat dataset besar terbukti lambat.
**Konsekuensi**: (+) Implementasi Fase 3 ringan & akurat real-time. (−) Bila nanti lambat di produksi (≫ puluhan ribu movement/hari), tambahkan `item_sales_daily` + job harian + observer `ORDER_SHIP`, lalu alihkan query analitik ke sana (tandai ADR ini Superseded).

## ADR-202 — Dua ambang + `is_stored` + skup lokasi
**Keputusan**:
- Tab **Menipis** (membership): `product_variants.min_stock > 0` AND `SUM(available) < min_stock`.
- **Target restock**: `qty_to_restock = (safe_stock > 0 ? safe_stock : min_stock) − available` (di-floor ke 0). `safe_stock ≥ min_stock` dijamin validasi form produk.
- **Semua tab monitoring memfilter `products.is_stored = true`** (produk non-stored tidak punya konsep stok kosong/menipis).
- Ambang **global per varian** (bukan per lokasi); bila filter lokasi aktif, agregasi `SUM` dibatasi ke lokasi itu. Reorder/safe per-lokasi → backlog.
**Alasan**: integrasi penuh field form produk (Batas stok menipis / Batas stok aman / Disimpan). Restock mengisi sampai level aman, bukan sekadar lewat ambang.
**Konsekuensi**: endpoint `need-restock` lama diselaraskan ke aturan ini (delegasi ke `MonitorStockService`).

## ADR-203 — Default threshold tab analitik (Fase 3)
**Keputusan**: konstanta config (belum UI setting): Dead-stock = **90 hari** tanpa `ORDER_SHIP`; Fast-moving window = **30 hari**; Forecast window = **30 hari**, tampil bila `days_to_out ≤ 30`. Bisa di-override via query param.
**Alasan**: angka wajar Jubelio; UI setting menyusul.

## ADR-204 — Sumber "Sedang Dibeli" (On Order)
**Keputusan**: **Membership tab** = varian yang muncul di `purchase_order_items` dengan `purchase_orders.status ∈ {OPEN, PARTIAL_RECEIVED}` (PO berjalan, sumber kebenaran). **Angka kolom On Order** = `SUM(inventories.on_order)` (counter cepat). Keduanya ditampilkan; bila perlu, `qty_pending_po = SUM(qty − received_qty)` dari PO.
**Alasan**: daftar granular dari PO, angka cepat dari counter; konsisten dengan kolom On Order di tabel.

## ADR-205 — Gagal Sync: sumber & retry (Fase 4)
**Keputusan**: list `ProductChannelMapping` `sync_status = 'failed'` (scope `failed()`), retry via dispatch `SyncStockToChannelsJob` (single & bulk). **Di luar scope Fase 0–2**, dicatat agar konsisten.
**Alasan**: data & job sudah ada; perlu endpoint list + retry saja.

---

## Tindak lanjut
- ADR-201/203 → Fase 3 (migrasi `item_sales_daily`, endpoint analitik).
- ADR-202/204 → **Fase 1–2 (dikerjakan sekarang)**: `MonitorStock*` (controller/service/repository/resource) + index.
- ADR-205 → Fase 4 (Gagal Sync).
