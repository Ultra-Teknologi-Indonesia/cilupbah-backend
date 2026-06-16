# Sales Module — Fixing Plan

Rencana perbaikan hasil audit modul `Sales` + integrasi (Inventory, Inbound, Channel, Outbound).
Diurutkan dari **Kritis → Penting → Minor**. Setiap item: akar masalah, strategi, file terdampak, dan cara verifikasi.

Prinsip umum:
- Mutasi stok harus **idempoten** & **berbasis selisih status (rank)**, bukan pencocokan pasangan diskret.
- Error bisnis/validasi = **4xx**, bukan 500. 500 hanya untuk kegagalan tak terduga.
- Tambah test regресi per fix; jaga `SalesNo500GuardTest` tetap hijau.

---

## FASE 1 — KRITIS (stok & 500)

### F1.1 — Perbaiki transisi stok channel (`reserved → packed → shipped`)
**Akar:** `applyChannelStockTransition()` hanya pick saat `previous === reserved`; transisi via `packed` melewati pick → `on_hand` tak berkurang, `reserved` menggantung.
**Strategi:** Refactor jadi reconciler berbasis `STATUS_RANK`. Jalankan langkah stok berurutan dari rank lama ke rank baru:
- naik melewati `reserved`  → `reserve` (jika belum)
- naik melewati `picked`/`packed` ke `shipped` → pastikan `pick` terjadi tepat sekali, lalu `ship`
- ke `cancelled` → `release` sesuai posisi efektif stok
Tambahkan penanda agar reserve/pick **tidak dobel** (mis. flag internal atau cek movement `source` per `transaction_number`).
**File:**
- `Modules/Sales/app/Services/SalesOrderService.php` (`applyChannelStockTransition`, `applyStockTransition`, helper stok)
**Verifikasi:** Unit test transisi: `pending→packed→shipped`, `reserved→packed→shipped`, `reserved→shipped`, `pending→cancelled`, `reserved→cancelled`, `packed→cancelled`. Assert delta `on_hand`/`reserved` benar & tidak dobel.

### F1.2 — Guard `reserve()` cek ketersediaan (cegah overselling)
**Akar:** `StockService::reserve()` hanya `reserved += qty`, tak pernah cek `available`; `InsufficientStockException` di-import tapi tak dipakai.
**Strategi:** Sebelum menambah `reserved`, hitung `available = on_hand - on_order - reserved`; jika `available < qty` → lempar `InsufficientStockException`.
Sediakan opsi **bypass terkontrol** untuk order yang sudah ter-commit di marketplace (channel) agar webhook tidak gagal total — mis. tetap reserve tapi catat warning + `AdminAlertJob`, sehingga stok internal boleh minus tapi termonitor. Untuk order **manual** → tegakkan exception (422).
**File:**
- `Modules/Sales/app/Services/StockService.php` (`reserve`, mungkin tambah param `bool $enforce = true`)
- `Modules/Sales/app/Services/SalesOrderService.php` (teruskan konteks manual vs channel)
**Verifikasi:** Test order manual stok kurang → 422 + tidak ada baris order. Test channel stok kurang → order tetap dibuat + alert.

### F1.3 — Null-guard `resolveLocationId()`
**Akar:** `DB::table('locations')->first()->id` → 500 bila tabel kosong / mapping tak ada.
**Strategi:** Jika tak ada `location_id`, mapping channel, maupun default location → lempar exception domain (mis. `LocationNotConfiguredException`) yang dipetakan ke 422 dengan pesan jelas. Hindari null-deref.
**File:**
- `Modules/Sales/app/Services/SalesOrderService.php` (`resolveLocationId`)
- `Modules/Sales/app/Exceptions/` (exception baru, opsional)
**Verifikasi:** Test tanpa lokasi → 422, bukan 500.

### F1.4 — Inventory row tak ada saat reserve → bukan 500
**Akar:** `reserve()` lempar `RuntimeException`; `SalesOrderController::store()` tanpa try/catch → 500.
**Strategi:** 
- Untuk order manual: validasi/normalisasi jadi 422 dengan pesan "stok item X di lokasi Y belum tersedia". 
- Pertimbangkan `findOrCreateForUpdate` (sudah ada di `InventoryRepository`) bila kebijakan bisnis mengizinkan auto-create baris inventory 0 — diskusikan; default: tolak 422.
- Pasang exception handler/mapping terpusat agar `InsufficientStockException`, `LocationNotConfiguredException`, dll → 422; `DuplicateOrderException` → 409.
**File:**
- `Modules/Sales/app/Services/StockService.php`
- `app/Exceptions/Handler.php` (atau handler modul) — render mapping
- `Modules/Sales/app/Http/Controllers/SalesOrderController.php`
**Verifikasi:** POST `/sales` SKU tanpa inventory → 422. `SalesNo500GuardTest` tetap hijau + test baru.

---

## FASE 2 — PENTING (konsistensi data & status code)

### F2.1 — Duplikasi order manual → 409, bukan 500
**Akar:** Idempotency key diset setelah commit; `salesorder_no` unik → race / re-POST nabrak unique → `QueryException` 500. `updateOrder` cancel tak `Cache::forget`.
**Strategi:**
- Tangkap unique violation di `createOrder` dan terjemahkan ke `DuplicateOrderException` (→ 409).
- Pindahkan/duplikasi `Cache::forget(idempotencyKey)` ke jalur cancel (`updateOrder` saat `cancelled`), bukan hanya `deleteOrder`.
- Map `DuplicateOrderException` ke 409 di handler.
**File:**
- `Modules/Sales/app/Services/SalesOrderService.php` (`createOrder`, `updateOrder`)
- `app/Exceptions/Handler.php`
**Verifikasi:** Re-POST `salesorder_no` sama → 409. Cancel lalu buat ulang nomor sama → sukses.

### F2.2 — Return `complete` tanpa restock
**Akar:** `complete()` izinkan dari `PENDING`, padahal restock (Inbound GRN) hanya di `accept()`. Bisa selesai tanpa barang masuk.
**Strategi:** Batasi `complete` hanya dari `ACCEPTED` (dan `COMPLETED` idempoten). Jika produk perlu masuk gudang, wajib `accept` dulu. Jika ada kebutuhan "complete tanpa restock" yang sah, buat field/flag eksplisit, bukan implisit.
**File:**
- `Modules/Sales/app/Services/SalesReturnService.php` (`complete`)
**Verifikasi:** Test `PENDING→complete` → ditolak (422). `ACCEPTED→complete` → sukses.

### F2.3 — Cegah overpayment & status pembayaran konsisten
**Akar:** `SalesPaymentService::create()` tak batasi `paid_amount` ≤ `total_amount`; `delete()` paksa status `OPEN` walau masih lunas.
**Strategi:**
- Saat create: tolak jika `paid_amount + amount > total_amount` (kebijakan strict) atau izinkan tapi tandai `OVERPAID` (kebijakan longgar) — pilih satu, default strict (422).
- Hitung ulang status dari `paid_amount` aktual: `>= total` → PAID, `>0` → PARTIAL/OPEN, `0` → OPEN. Terapkan di create **dan** delete.
**File:**
- `Modules/Sales/app/Services/SalesPaymentService.php` (`create`, `delete`)
**Verifikasi:** Test bayar > sisa → ditolak/over. Test hapus 1 dari banyak pembayaran → status tetap benar.

### F2.4 — Jangan kembalikan 500 mentah untuk error bisnis
**Akar:** Controller `SalesReturn*`, `SalesInvoice*`, `SalesReturnSettlement*` pakai `catch(\Exception){ errorResponse($e->getMessage(), 500) }` → bocorkan pesan internal & ubah konflik bisnis jadi 500.
**Strategi:**
- Buat exception domain spesifik (mis. `ReturnAlreadyProcessedException`) → map ke 409/422 di handler.
- Hapus try/catch generic di controller; biarkan handler global yang memetakan. Sisakan 500 hanya untuk kasus benar-benar tak terduga (tanpa bocorkan message di production).
**File:**
- `Modules/Sales/app/Http/Controllers/SalesReturnController.php`
- `Modules/Sales/app/Http/Controllers/SalesInvoiceController.php`
- `Modules/Sales/app/Http/Controllers/SalesReturnSettlementController.php`
- `Modules/Sales/app/Exceptions/*`, `app/Exceptions/Handler.php`
**Verifikasi:** "Return sudah berstatus X" → 409, bukan 500.

---

## FASE 3 — MINOR (kebersihan & korektif kecil)

### F3.1 — `SalesInvoiceService::createOrUpdate` benar-benar create-or-update
**Strategi:** Jika `invoice_number` diberikan & sudah ada → update; jika tidak → create. Atau rename jadi `create()` dan tegakkan unik (→ 409). 
**File:** `Modules/Sales/app/Services/SalesInvoiceService.php`

### F3.2 — `markAsComplete` aman untuk order `packed` asal-channel
**Strategi:** Setelah F1.1, pastikan `markAsComplete` memakai reconciler stok yang sama (pick bila belum, lalu ship) sehingga `on_hand` selalu benar.
**File:** `Modules/Sales/app/Services/SalesOrderService.php` (`markAsComplete` → pakai path stok terpadu)

### F3.3 — Konsistensi `cancel_reason` vs `cancel_request_reason`
**Strategi:** Samakan kolom yang diisi `CancelChannelOrderJob`/`updateOrder` dengan yang difilter `getFailedOrders` (`cancel_request_reason`). Tentukan satu sumber kebenaran.
**File:** `Modules/Sales/app/Repositories/SalesOrderRepository.php`, `Modules/Sales/app/Jobs/CancelChannelOrderJob.php`, `Modules/Sales/app/Services/SalesOrderService.php`

### F3.4 — Auto-accept return: surfacing kegagalan
**Strategi:** Saat auto-accept gagal (`create()`), selain `Log::warning`, kirim `AdminAlertJob` agar tidak diam-diam tertinggal `PENDING`.
**File:** `Modules/Sales/app/Services/SalesReturnService.php`

### F3.5 — Idempotency key lifecycle
**Strategi:** Pastikan semua jalur terminal (delete, cancel) membersihkan key; dokumentasikan TTL `IDEMPOTENCY_TTL`.
**File:** `Modules/Sales/app/Services/SalesOrderService.php`

---

## Fondasi lintas-fase (kerjakan di awal Fase 1)

1. **Exception → HTTP mapping terpusat** di `app/Exceptions/Handler.php`:
   - `InsufficientStockException`, `InvalidStatusTransitionException`, `LocationNotConfiguredException` → 422
   - `DuplicateOrderException` → 409
   - `CannotDeleteActiveOrderException` → 409
   Ini menopang F1.2–F1.4, F2.1, F2.4 sekaligus.
2. **Helper stok terpadu** (`applyStockTransition` berbasis rank) dipakai bersama oleh jalur manual (`updateOrder`, `markAsComplete`) dan channel (`applyChannelStockTransition`) untuk hindari logika ganda.

---

## Urutan eksekusi yang disarankan

1. Fondasi: exception mapping + helper stok terpadu.
2. F1.1 → F1.2 → F1.3 → F1.4 (stok & 500 inti).
3. F2.1 → F2.4 → F2.2 → F2.3 (konsistensi & status code).
4. F3.x (kebersihan).
5. Update graph: `graphify update .` setelah perubahan.

## Strategi test
- Tambah `tests/Feature` & unit untuk tiap fix di atas.
- Pertahankan `SalesNo500GuardTest`, `ChannelStockShipTransitionTest`, `SyncOrderItemsTest`, `SalesReturnSettingTest`.
- Tambah test transisi stok end-to-end (manual & channel) sebagai jaring pengaman utama F1.1.
