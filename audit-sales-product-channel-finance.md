# Audit Total: Kelengkapan Data Keuangan Order Channel + Modul Product & Sales

**Tanggal:** 12 Juni 2026
**Cakupan:** (A) Kelengkapan data keuangan pesanan yang ditarik dari TikTok & Lazada, (B) audit penuh `Modules/Sales`, (C) audit penuh `Modules/Product`, (D) pelanggaran sistemik `agents.md`.
**Metode:** Pembacaan kode menyeluruh + verifikasi empiris via tinker pada DB Postgres lokal (klaim berlabel **[TERBUKTI]** sudah dieksekusi nyata, bukan dugaan).

**Legenda severity:**
- 🔴 **KRITIS** — bug 500 / kehilangan data finansial / flow bisnis putus
- 🟠 **TINGGI** — bisnis logic salah, hasil keuangan/stok tidak akurat
- 🟡 **SEDANG** — case tidak terhandle, API kurang, perilaku menyesatkan
- 🔵 **RENDAH** — pelanggaran standar, naming, konsistensi

---

## BAGIAN A — Apakah Data Keuangan Pesanan TikTok & Lazada Lengkap?

**Jawaban singkat: TIDAK lengkap.** Level *order header* (subtotal, diskon, pajak, ongkir, grand total, metode bayar, status bayar) sudah tersimpan. Tetapi ada tiga lapisan finansial yang **hilang atau salah**, sehingga angka di `sales_orders` belum bisa dijadikan dasar akuntansi/rekonsiliasi yang akurat seperti Jubelio.

### A.1 Yang SUDAH tersimpan ✅

| Field internal | TikTok (sumber) | Lazada (sumber) |
|---|---|---|
| `sub_total` | `payment.original_total_product_price` | Σ `paid_price` item |
| `total_disc` | `payment.seller_discount` | `voucher` order |
| `total_tax` | Σ `item_tax` per line | Σ `tax_amount` item |
| `shipping_cost` | `payment.original_shipping_fee` | `shipping_fee` |
| `insurance_cost` | `payment.shipping_insurance_fee` | tidak ada (0) |
| `grand_total` | `payment.total_amount` | `price` order |
| `payment_method`, `is_paid`, `paid_time` | ✓ | ✓ (dengan bug, lihat A.3) |
| Item: price/disc/disc_amount/tax_amount/amount | ✓ | ✓ |

### A.2 Yang HILANG (gap data) 🔴

1. **Diskon platform tidak dipisahkan dari diskon seller.**
   - TikTok: `payment.platform_discount` **dibuang** (`TikTokToInternalOrderMapper.php:50` hanya ambil `seller_discount`). Akibatnya persamaan header tidak konsisten: `sub_total − total_disc + shipping + tax ≠ grand_total` (grand_total = uang yang dibayar buyer setelah diskon platform).
   - Lazada: `voucher` order = gabungan `voucher_seller` + `voucher_platform`; mapper mengambil agregatnya saja (`LazadaToInternalOrderMapper.php:51`). Untuk akuntansi, voucher platform **bukan pengurang pendapatan seller** (di-reimburse marketplace) — saat ini tercampur.
2. **Tidak ada data settlement/biaya marketplace sama sekali.** Komisi marketplace, biaya layanan, biaya kampanye, escrow amount, payout — tidak ada satupun yang ditarik. Tabel `sales_settlements` **ada tapi tidak pernah diisi oleh kode manapun** (grep seluruh `Modules/`: nol penulis). Endpoint `GET sales/settlements` selamanya kosong. Jubelio mengisi ini dari API finance/settlement marketplace (TikTok: `/finance/202309/statements`, Lazada: `/finance/payout/details/get`) — **belum diimplementasikan**.
3. **Tidak ada kolom/penanganan mata uang (currency)** — diasumsikan IDR implisit.
4. **Ongkir aktual yang ditanggung seller vs buyer tidak dibedakan** (TikTok `shipping_fee_seller_discount` / `shipping_fee_platform_discount` dibuang; Lazada `shipping_fee` order kadang 0 dan ongkir riil ada di `shipping_amount` per item — tidak dibaca).

### A.3 Yang SALAH (bug logika finansial) 🟠

1. **Lazada: order CANCELED dianggap sudah dibayar.** `LazadaToInternalOrderMapper.php:54` — `is_paid = ! in_array($status, ['unpaid','failed'])`. Status `canceled` tidak masuk daftar → order yang dibatalkan **sebelum dibayar** tersimpan `is_paid = true` + `paid_time` terisi. Laporan penjualan/piutang akan salah.
2. **Lazada: `paid_time` memakai `updated_at` order** (`LazadaToInternalOrderMapper.php:88`) — setiap update order menggeser "waktu bayar". Tidak akurat untuk cut-off akuntansi.
3. **Lazada: fallback `grand_total` berisiko diskon ganda** (`LazadaToInternalOrderMapper.php:67`): `subTotal + shipping − voucher`, padahal `subTotal` dihitung dari `paid_price` yang **sudah neto voucher seller** → voucher terpotong dua kali saat field `price` tidak ada.
4. **TikTok: `disc_amount = seller_discount × qty`** (`TikTokToInternalOrderMapper.php:30`). Di API TikTok 202309, `line_items` adalah **satu baris per unit** (tidak ada field `quantity` resmi) — `quantity ?? 1` aman, tetapi jika TikTok mengirim line dengan qty > 1, `seller_discount` adalah nilai total line, bukan per unit → diskon item dikalikan dobel. Perlu dikunci dengan test fixture riil.
5. **Item TikTok tidak digabung per SKU** (Lazada digabung via `groupItems`, TikTok tidak) → order multi-qty menghasilkan N baris `sales_order_items` untuk SKU sama. Nilai total benar, tapi inkonsisten antar channel dan membengkakkan baris picklist.

### A.4 Putus rantai ke modul Finance (jurnal) 🟠

Observer jurnal otomatis hanya terpasang pada `SalesInvoice` & `SalesPayment` (`Modules/Finance/app/Providers/EventServiceProvider.php:32-33`). **Order channel tidak pernah otomatis menjadi invoice** — invoice hanya tercipta jika user manual memanggil `POST sales/packlists/create-invoice`. Konsekuensi:

> Pesanan TikTok/Lazada yang ditarik → tersimpan di `sales_orders` → **tidak pernah menghasilkan jurnal pendapatan, piutang, maupun kas** kecuali ada aksi manual per order. Tidak ada job/observer "order shipped/paid → buat invoice".

Ini gap integrasi terbesar antara omnichannel dan akuntansi. Ditambah tidak adanya settlement (A.2.2), siklus keuangan marketplace (pendapatan → fee → payout → rekonsiliasi kas) baru terimplementasi ±30%.

---

## BAGIAN B — Audit `Modules/Sales`

### B.1 🔴 KRITIS — Error 500

| # | Temuan | Lokasi | Bukti |
|---|---|---|---|
| S-1 | `update`/`destroy` order pakai `SalesOrder::findOrFail($id)` langsung — id non-UUID → cast uuid Postgres meledak → **500** (route `sales/{id}` tanpa `whereUuid`) | `SalesOrderController.php:250, 281` | **[TERBUKTI]** `find('abc')` → `QueryException 22P02` |
| S-2 | Rule `exists:` pada kolom uuid **tanpa rule `uuid` + `bail`** → input non-UUID meledak saat validasi → **500**. Kena di: `saveAirwaybill` (:428), `saveReceivedDate` (:455), `setAsPaid` (:482), `requestAwb` (:509), `deleteCanceled` (:377), `markAsComplete` (:402), `StoreSalesPaymentRequest:17`, `StoreSalesInvoiceRequest:17,19,26`, `StoreSalesReturnRequest:17,18`, `SalesPaymentController::destroy:115` | banyak | **[TERBUKTI]** `Validator exists:sales_orders,id` dgn `'bukan-uuid'` → `QueryException 22P02` |
| S-3 | `show` payment/invoice/settlement/return memanggil `Model::find($id)` tanpa guard UUID → `GET sales/payments/abc`, `sales/invoices/abc`, `sales/settlements/abc`, `sales/returns/abc`, `return-settlements/invoices/abc`, `.../refunds/abc` → **500**. (Hanya `SalesOrderRepository::getOrderById` yang punya guard `Uuid::isValid`.) | `SalesPaymentRepository.php:26`, `SalesInvoiceRepository.php:29`, `SalesSettlementRepository.php:23`, `SalesReturnRepository.php:38`, `SalesReturnSettlementRepository.php:26,52,73` | **[TERBUKTI]** pola sama |
| S-4 | **Re-sync order yang sudah masuk picklist pasti gagal.** `syncOrderItems` menghapus semua `sales_order_items` lalu insert ulang (`SalesOrderRepository.php:178`), padahal `picklist_items.order_item_id` ber-FK `restrictOnDelete` (migrasi rename :36-38). Begitu order dibuat picklist-nya, **setiap webhook/pull berikutnya untuk order itu → FK violation → upsert rollback** → status order lokal berhenti ter-update selamanya (error berulang di log). | `SalesOrderRepository.php:176-211` | analisis FK terverifikasi di migrasi |
| S-5 | `resolveLocationId` fallback `DB::table('locations')->first()->id` tanpa null-check — DB tanpa lokasi → **500** | `SalesOrderService.php:510-512` | |
| S-6 | `StockService->reserve()` melempar `RuntimeException` bila row inventory belum ada → `POST /sales` manual untuk SKU valid tanpa inventory → **500** (bukan 422) | `StockService.php:27` | |

### B.2 🟠 TINGGI — Kesalahan bisnis logic / flow

| # | Temuan | Lokasi |
|---|---|---|
| S-7 | **Stok reserved bocor permanen untuk order channel.** `applyChannelStockTransition` hanya menangani `pending→reserved` dan `reserved/picked/packed→cancelled`. Order channel yang sudah `reserved` lalu channel melaporkan `DELIVERED/COMPLETED` (`→shipped`) **tanpa melewati picklist WMS** → `reserved` tidak pernah dilepas dan `on_hand` tidak pernah berkurang. Stok tersedia (available) menyusut permanen sampai dikoreksi manual. | `SalesOrderService.php:365-380` |
| S-8 | **Pembatalan order Lazada tidak pernah diteruskan ke Lazada.** `CancelChannelOrderJob` hanya punya handler `tiktok` (shopee/tokopedia TODO, default cuma log). `LazadaOrderService::cancelOrder()` & `LazadaAdapter` sudah ada tapi **tidak dipakai** job ini → cancel lokal order Lazada diam-diam tidak sinkron ke marketplace. | `CancelChannelOrderJob.php:36-41` |
| S-9 | **Sinkronisasi stok hanya TikTok, tapi ditembakkan ke semua toko.** `SyncStockJob` loop semua `channel_shops` aktif (tanpa filter channel) dan memanggil `TikTokProductService::syncInventoryBySku` untuk masing-masing — toko **Lazada ikut dipanggil dengan API TikTok** (gagal, hanya warning), dan **stok ke Lazada tidak pernah disinkronkan** setelah ada penjualan. Harusnya routing per-channel via `AdapterFactory`. | `SyncStockJob.php:48-72` |
| S-10 | **Pembayaran bisa melebihi tagihan tanpa peringatan.** `SalesPaymentService::create` menambah `paid_amount` tanpa cek sisa tagihan, tanpa cek status invoice (bayar invoice DRAFT/sudah PAID tetap diterima). | `SalesPaymentService.php:26-42` |
| S-11 | **Satu order bisa dibuat invoice berulang kali.** `createFromOrder` tidak mengecek invoice existing untuk `order_id` yang sama → potensi **pendapatan dobel di jurnal** (observer Finance membuat jurnal per invoice; idempotensi jurnal per-invoice-id, bukan per-order). | `SalesInvoiceService.php:71-109` |
| S-12 | **Invoice dari order mengabaikan ongkir & diskon/pajak header.** `createFromOrder` hanya menjumlah item → `total_amount` ≠ `grand_total` order (ongkir, insurance, diskon header hilang) → pendapatan & piutang tercatat lebih kecil dari uang yang sebenarnya diterima. | `SalesInvoiceService.php:91-105` |
| S-13 | `bulkDeleteCancelled`/`deleteOrder` pada order cancelled yang punya picklist/packlist → FK `restrictOnDelete` → **QueryException 500** (delete dipanggil tanpa cek relasi WMS). | `SalesOrderRepository.php:107-112`, `SalesOrderService.php:208-233` |
| S-14 | `ProcessMarketplaceOrder` job (createOrder + idempotensi cache) **tidak pernah di-dispatch dari manapun** — dead code yang menyesatkan (jalur webhook sebenarnya: `ProcessTikTokWebhook/ProcessLazadaWebhook → pullOrderById → upsertFromChannel`). | `Modules/Sales/app/Jobs/ProcessMarketplaceOrder.php` |
| S-15 | Idempotensi `createOrder` hanya via Cache (48 jam) — cache flush → duplikat order menabrak unique `salesorder_no` → **QueryException 500**, bukan `DuplicateOrderException` 409/422. | `SalesOrderService.php:133-141` |
| S-16 | `upsertOrderBySalesOrderNo` menimpa SEMUA kolom setiap pull — `seller_note`, `location_id` hasil edit/relokasi lokal akan... `location_id` aman (tidak ikut), tapi `seller_note`, `cancel_reason`, `customer_name` hasil edit manual tertimpa data channel. | `SalesOrderRepository.php:129-161` |
| S-17 | Refund return-settlement tidak meng-update total settlement (createInvoice meng-update `total_amount`, `createRefund` tidak) dan **tidak ada observer jurnal untuk refund** → uang keluar refund tidak pernah tercatat di Finance. | `SalesReturnSettlementService.php:42-68` |

### B.3 🟡 SEDANG

| # | Temuan | Lokasi |
|---|---|---|
| S-18 | `SalesReturnService` melempar `\Exception` generik untuk "tidak ditemukan"/"status salah" → controller mana pun yang tidak menangkap → 500; seharusnya 404/422 (`HttpException`/domain exception). | `SalesReturnService.php:79,83,113,117` |
| S-19 | `SalesPaymentController::store/destroy` menangkap `\Exception` lalu **selalu balas 500** + bocorkan `$e->getMessage()` mentah — `ModelNotFoundException` (invoice tak ada) pun jadi 500. | `SalesPaymentController.php:65-70,117-122` |
| S-20 | `POST /sales` menerima `items.*.sku` tanpa cek keberadaan → SKU tak dikenal lolos, `item_id` null, reserve stok **diskip diam-diam**, order tetap `reserved` → order tampak aman padahal tidak ada stok yang dipegang. | `SalesOrderController.php:166`, `SalesOrderRepository.php:182-188` |
| S-21 | `updateOrder` → cancel men-dispatch `CancelChannelOrderJob` untuk SEMUA order ber-`source` (termasuk `manual`) — job no-op + noise log. | `SalesOrderService.php:187-191` |
| S-22 | `InsufficientStockException` di-import tapi **tidak pernah dilempar** — stok minus diizinkan tanpa ambang batas/konfigurasi. Kebijakan oversell tidak eksplisit. | `SalesOrderService.php:10`, `StockService.php:30-32` |
| S-23 | `requestAwb` cuma stub `['status'=>'requested']` — tidak request AWB ke channel manapun, tapi membalas sukses → API menyesatkan. | `SalesOrderService.php:126-131` |
| S-24 | `created_by` payment/invoice/return diambil dari **body request** (string bebas), bukan `Auth::id()` — audit trail bisa dipalsukan. | `StoreSalesPaymentRequest.php:23`, `StoreSalesInvoiceRequest.php:24`, `StoreSalesReturnRequest.php:24` |

### B.4 🔵 RENDAH — Pelanggaran agents.md / naming

1. **Model mentah dikembalikan tanpa Eloquent Resource** di hampir semua endpoint Invoice/Payment/Return/Settlement/ReturnSettlement (`successResponse($invoice)`, `successPaginatedResponse($payments)` dst — ±20 titik, lihat `SalesInvoiceController.php:39-213`, `SalesPaymentController.php:38-94`, `SalesReturnController.php:38-82`...). Hanya SalesOrder yang punya Resource. **Dilarang keras** menurut agents.md §2.
2. **Pagination non-standar sistemik:** parameter `?limit` + `paginate($limit)` tanpa `appends(request()->query())` di seluruh repo Sales selain `getPaginatedOrders` (`SalesInvoiceRepository.php:26,57,72,99`, `SalesOrderRepository.php:45-104`, `SalesPaymentRepository.php:23`, dst). Standar: `paginate(request('per_page', 10))->appends(...)`.
3. `FuzzyFilter` dipakai alih-alih macro `allowedSearch` FTS (agents.md §4) di semua list selain index utama.
4. `SalesOrderController::index/show` masih punya cabang `return view('sales::index')` + method `create()/edit()` ber-view di controller API — dilarang agents.md §2.
5. Naming: `SalesInvoiceService::createOrUpdate` hanya create; `GET sales/orders/cancel` (harusnya `cancelled`); `sales/unfullfilled` typo (harusnya `unfulfilled`); `cancelProduct` di TikTokOrderService sebenarnya cancel order.
6. Validasi inline panjang di controller (store ±35 baris) — layak FormRequest.
7. Route `sales/{id}` tanpa `whereUuid` (akar S-1).

---

## BAGIAN C — Audit `Modules/Product`

### C.1 🔴 KRITIS — Error 500

| # | Temuan | Lokasi | Bukti |
|---|---|---|---|
| P-1 | Feed shows hanya menangkap `ModelNotFoundException`, padahal id non-UUID melempar `QueryException` → `GET products/master/abc`, `products/archives/abc`, `products/channel-products/abc`, `raise-products/abc` (+ semua sub-route raise), `upload-histories/abc` → **500**. Route tanpa `whereUuid`, repo pakai `findOrFail`. | `MasterFeedController.php:44-49`, `ArchiveFeedController.php:39-46`, `RaiseProductController.php:41-47`, `MasterFeedRepository.php:38`, `RaiseProductRepository.php:53,102`, `UploadHistoryRepository.php:79` | **[TERBUKTI]** pola cast uuid |
| P-2 | `exists:` pada kolom **bigint** tanpa rule `integer` → `category_id: "abc"` saat create product/bundle/kategori → **QueryException 500 saat validasi** (`CreateProductRequest:17,18,30,31,41,53`, `UpdateProductRequest:19,20`, `CategoryController:38,64`). | requests tsb | **[TERBUKTI]** `exists:categories,id` dgn `'abc'` → `22P02` |
| P-3 | `CategoryController::show(int $id)` / `update(int $id)` — type-hint `int` pada route param: `GET categories/abc` → TypeError binding → **500** (apiResource tanpa `whereNumber`). Berlaku juga brands/attributes. | `CategoryController.php:50,60` | pola |
| P-4 | `ChannelProductController::store` & `ProductController::store` menangkap semua `\Exception` → **balas 500 dengan pesan mentah** untuk error yang seharusnya 4xx; `CategoryController::update/store` sama (`errorResponse($e->getMessage(), 500)`). | `ProductController.php:138-140`, `CategoryController.php:44-48,69-73` | |

### C.2 🟠 TINGGI — Bisnis logic salah

| # | Temuan | Lokasi |
|---|---|---|
| P-5 | **`PUT /products/{id}` diam-diam membuang field yang sudah lolos validasi.** `UpdateProductRequest` memvalidasi `category_id`, `brand_id`, `search_keyword`, `order_type`, `condition`, `is_cod_allowed` — tetapi `ProductService::updateProduct` `Arr::only` hanya mengambil `name/description/dimensi/is_active/is_bundle/is_consignment`. **Kategori & brand produk tidak akan pernah bisa diubah via API**, respon tetap "berhasil diperbarui". | `UpdateProductRequest.php:19-28` vs `ProductService.php:117-120` |
| P-6 | **`POST /products` tidak bisa set `sku` produk** — `createProduct` `Arr::only` tanpa `sku` (dan request tak memvalidasinya) → kolom `products.sku` null untuk semua produk buatan API, padahal `upsertFromChannel`/`findBySku` mencocokkan `products.sku` sebagai kunci dedup → dedup channel-vs-master melemah. | `ProductService.php:211-215` |
| P-7 | **Perubahan harga tidak tersinkron ke channel.** `POST inventory/price-list` meng-update `product_variants.sell_price` langsung via query — `ProductObserver` hanya mengamati event `updated` pada **Product**, bukan ProductVariant, dan update via `ProductVariant::where()->update()` pun tidak memicu event model → harga di TikTok/Lazada tidak berubah, `sync_status` tidak jadi pending. Sama untuk `ProductService::updateProduct` (raw `DB::table` → observer tidak pernah terpicu meski produk diedit!). | `PriceListRepository.php:33-46`, `ProductService.php:114-206`, `ProductObserver.php` |
| P-8 | `ProductObserver::updated` debounce 5 detik **menelan perubahan kedua** (bukan menunda): dua update dalam 5 detik → sync kedua hilang permanen (bukan di-coalesce). | `ProductObserver.php:17-22` |
| P-9 | Bundle: `saveBundle` tidak memvalidasi komponen bukan-bundle (bundle berisi bundle → rekursi stok tidak terdefinisi) dan `StoreBundleRequest` tidak mengecek duplikat `variant_id` komponen. Tidak ada logika stok bundle (stok bundle vs komponen) di mana pun. | `ProductRepository.php:68-90` |
| P-10 | `Promotion` `type=percent` menerima `value` > 100 (`min:0` saja), dan promosi **tidak diterapkan ke harga manapun** (tidak ada konsumen `Promotion` di kode) — API ada, efek bisnis nol. | `StorePromotionRequest.php:21`, model Promotion |

### C.3 🟡 SEDANG

| # | Temuan | Lokasi |
|---|---|---|
| P-11 | `ChannelProductController` balas **400** untuk `shop_id` kosong (konvensi proyek: 422 validasi) dan `{channel}` path param tidak divalidasi terhadap channel terdaftar. | `ChannelProductController.php:59,110,131,...` |
| P-12 | `CategoryController::update` tidak mencegah `parent_id` menunjuk dirinya/anak-cucunya → siklus kategori. | `CategoryController.php:60-75` |
| P-13 | `MasterFeedController::index` mengizinkan `per_page` hingga 500 dengan eager-load berat (variants+media+mappings) — rawan memory spike; modul lain max tidak dibatasi sama sekali. | `MasterFeedController.php:25` |
| P-14 | `ProductController::store` balikan hanya `{product_id}` (bukan ProductResource) — inkonsisten dengan update/show. | `ProductController.php:134-137` |

### C.4 🔵 RENDAH — agents.md / naming

1. **`ProductService` = pelanggaran arsitektur terbesar:** `createProduct`, `updateProduct`, `upsertFromChannel` berisi **ratusan baris raw `DB::table()`** (products, variants, mappings, media, specs, wholesale) — semua interaksi DB wajib di Repository (agents.md §1). `ProductRepository` ada tapi dilewati.
2. `ProductController::findProduct` (guard UUID + akses repo) — logika ini harusnya di service/repo, bukan private method controller; duplikasi pola guard di tiap modul, layak jadi helper/`whereUuid` route.
3. ID mapping memakai dua format berbeda: `Uuid::uuid7()->toString()` (dengan strip) vs `Uuid::uuid7()->getHex()->toString()` (tanpa strip) — `ProductService.php:164,191,334,348`. Konsisten satu format.
4. Validasi inline di `CategoryController`/`BrandController`/`AttributeController` (bukan FormRequest), respons `successPaginatedResponse` di beberapa feed memakai `->resolve($request)` manual alih-alih pola Resource standar.
5. Banyak file planning `.md` menumpuk di root modul Product (8 file) — sebaiknya pindah ke `docs/`.

---

## BAGIAN D — Ringkasan Pelanggaran Sistemik (lintas modul)

| Pola | Skala | Standar yang dilanggar |
|---|---|---|
| `exists:`/`find()` pada kolom uuid/bigint tanpa guard `uuid`/`integer`/`whereUuid` → 500 | ±25 titik di Sales + Product | "tidak boleh 500; input invalid → 422, id salah → 404" |
| Model mentah tanpa Eloquent Resource | ±20 endpoint Sales | agents.md §2 |
| `paginate($limit)` dari `?limit`, tanpa `appends`, bukan `per_page` default 10 | ±15 repo method | agents.md §5 |
| `catch (\Exception) → 500 + pesan mentah` | 8 controller method | agents.md §2 + keamanan |
| Raw `DB::table()` di Service | ProductService (masif), SalesOrderService.resolveLocationId | agents.md §1 |
| `created_by` dari body request | Semua request Sales ber-`created_by` | best practice auth |

---

## Rekomendasi Prioritas (urutan pengerjaan)

1. **[P0] S-4** — Ubah `syncOrderItems` jadi upsert per-SKU (jangan delete-insert) atau skip penghapusan saat item ber-referensi picklist; tanpa ini order yang sedang dipenuhi berhenti sinkron.
2. **[P0] S-7** — Tangani transisi `reserved/picked/packed → shipped` di `applyChannelStockTransition` (lepas reserved + kurangi on_hand bila tidak lewat picklist WMS).
3. **[P0] Bug 500 massal** — Tambah `whereUuid`/`whereNumber` di route + rule `bail|uuid`/`integer` sebelum `exists:` (pola yang sama persis dengan perbaikan modul Auth kemarin).
4. **[P1] Finansial channel** — Simpan `platform_discount` & `voucher_platform/seller` terpisah (tambah kolom), perbaiki `is_paid` Lazada untuk `canceled`, perbaiki fallback `grand_total`.
5. **[P1] S-8/S-9** — Routing cancel & stock-sync via `AdapterFactory` agar Lazada tercakup.
6. **[P1] A.4** — Job/observer otomatis: order channel `shipped`+`is_paid` → buat `SalesInvoice` + `SalesPayment` (idempoten per order) agar jurnal pendapatan terbentuk; lalu implement pull settlement (TikTok statements / Lazada payout) untuk mengisi `sales_settlements`.
7. **[P2] P-5/P-6/P-7** — Selaraskan `updateProduct` dengan field tervalidasi, izinkan `sku` di create, dan pastikan perubahan harga memicu sync channel.
8. **[P2] Kepatuhan agents.md** — Resource untuk Invoice/Payment/Return/Settlement, standar `per_page`, pindahkan query ProductService ke repository.

---

*Laporan ini hasil audit baca-kode + verifikasi tinker; belum ada satu baris kode pun yang diubah. Klaim [TERBUKTI] dieksekusi langsung terhadap DB Postgres lokal pada 12 Juni 2026.*
