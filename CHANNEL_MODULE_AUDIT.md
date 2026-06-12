# Audit Modul `Modules/Channel`

> Tanggal: 2026-06-12
> Lingkup: bug, error 500 saat di-hit, N+1 query, kesalahan logika/flow bisnis, use case yang belum di-handle, dan pelanggaran `agents.md`.
> Catatan: dokumen ini **hanya analisis + planning**. Tidak ada perubahan kode yang dilakukan.

---

## Ringkasan Eksekutif

Sisi **Lazada** (controller/service/route) tergolong rapi dan konsisten dengan `agents.md`: ID memakai `string` + `whereUuid`, webhook pakai `hash_equals`, dedup, dan pola "never-500". Sisi **TikTok** dan beberapa bagian generik **tertinggal** dan menyimpan beberapa bug serius:

- **2 endpoint kategori CRITICAL** yang hampir pasti **HTTP 500** saat dipanggil (UUID dilewatkan ke parameter ber-tipe `int`).
- **Kebocoran kredensial** (`access_token`/`refresh_token`/`shop_cipher`) pada 2 endpoint listing.
- **1 endpoint cancel** yang secara logika **selalu gagal**.
- **Bug lintas-channel** pada auto-sync TikTok.
- Sejumlah pelanggaran arsitektur `agents.md` (query DB di Service, `view()`/flash session di API controller, pagination default ≠ 10).

Tabel prioritas ada di bagian [Planning](#planning--rencana-perbaikan).

---

## 🔴 CRITICAL

### C-1. Endpoint store TikTok melempar 500 — UUID dilewatkan ke parameter `int`
**Dampak:** `GET /api/v1/tiktok/stores/{id}`, `DELETE /api/v1/tiktok/stores/{id}`, `POST /api/v1/tiktok/stores/{id}/refresh-token` → **HTTP 500** setiap dipanggil dengan id nyata.

**Akar masalah:** `channel_shops.id` sekarang **UUID** (lihat `database/migrations/2026_06_06_120600_change_channel_ids_to_uuid.php` + trait `App\Traits\HasUuid7` yang menghasilkan UUID ber-hyphen via `Uuid::uuid7()->toString()`). Namun rantai pemanggilan masih bertipe `int`:

- Route tanpa `whereUuid`: [`routes/api.php:26-28`](Modules/Channel/routes/api.php:26)
- Controller: `TikTokStoreController::show(int $id)`, `destroy(int $id)`, `refreshToken(int $id)` — [`TikTokStoreController.php:34,44,54`](Modules/Channel/app/Http/Controllers/TikTokStoreController.php:34)
- Service: `TikTokAuthService::getStoreDetail(int $id)`, `disconnectStore(int $id)`, `refreshStoreToken(int $id)` — [`TikTokAuthService.php:86,107,117`](Modules/Channel/app/Services/TikTokAuthService.php:86)
- Repository: `ChannelShopRepository::findById(int $id)`, `disconnectShop(int $id)`, `updateTokens(int $id)` — [`ChannelShopRepository.php:72,77,88`](Modules/Channel/app/Repositories/ChannelShopRepository.php:72)

UUID seperti `0190ab...-...-...` tidak bisa dikoersi ke `int` → **`TypeError`**. Karena `TypeError` adalah `Error` (bukan `Exception`), blok `catch (\Exception $e)` di controller **tidak menangkapnya** → bocor jadi 500.

**Jejak yang sama (web route, dipakai blade internal):**
`POST /channels/shop/{id}/disconnect` & `/refresh-token` → `ChannelController::disconnectShop(Request, int $id)` / `refreshShopToken(int $id)` ([`web.php:7-8`](Modules/Channel/routes/web.php:7), [`ChannelController.php:61,80`](Modules/Channel/app/Http/Controllers/ChannelController.php:61)).

**Pembanding yang benar:** sisi Lazada sudah pakai `string $id` + `->whereUuid('id')` ([`routes/api.php:52-54`](Modules/Channel/routes/api.php:52)).

**Usulan perbaikan:**
- Ubah seluruh parameter `int $id` → `string $id` pada Controller/Service/Repository TikTok di atas.
- Tambahkan `->whereUuid('id')` pada 3 route `tiktok/stores/{id}` (dan pertimbangkan untuk web route `channels/shop/{id}`).

---

### C-2. Kebocoran kredensial toko (access_token / refresh_token / shop_cipher)
**Dampak:** Respons API membocorkan token mentah.

**Akar masalah:** Model `ChannelShop` **tidak punya `$hidden`** ([`ChannelShop.php:10-29`](Modules/Channel/app/Models/ChannelShop.php:10)), sehingga serialisasi mentah mengekspos `access_token`, `refresh_token`, `shop_cipher`.

Endpoint yang menyerialisasi model mentah (bukan lewat Resource):
- `TikTokStoreController::index` → `successResponse($this->shopRepository->getPaginatedShops())` ([`TikTokStoreController.php:27-28`](Modules/Channel/app/Http/Controllers/TikTokStoreController.php:27)). Paginator berisi `ChannelShop` mentah → token bocor.
- `ChannelController::index` → `getPaginatedChannels()` melakukan `->with('shops')` lalu `successResponse($channels)` ([`ChannelController.php:43`](Modules/Channel/app/Http/Controllers/ChannelController.php:43), [`ChannelRepository.php:14`](Modules/Channel/app/Repositories/ChannelRepository.php:14)). Setiap channel membawa array `shops` mentah → token bocor.

> Catatan: `GET /marketplace/store` (`ChannelController::stores`) **aman** karena memakai `ChannelShopResource` yang sengaja menyembunyikan token. `TikTokAuthService::getStores()` juga aman tapi **tidak dipakai** oleh `TikTokStoreController::index`.

**Usulan perbaikan:**
- Tambahkan `protected $hidden = ['access_token','refresh_token','shop_cipher']` pada model `ChannelShop` (pertahanan lapis pertama).
- Ubah kedua endpoint agar memakai `ChannelShopResource` + `successPaginatedResponse` (transformasi resource diterapkan dulu — `agents.md §2`).

---

## 🟠 HIGH

### H-1. Auto-sync TikTok memproses toko milik channel lain (lintas-channel) — ✅ FIXED
**Lokasi:** [`TikTokSyncApiController::pullOrdersAll` & `pullProductsAll`](Modules/Channel/app/Http/Controllers/TikTokSyncApiController.php:26) → `ChannelShopRepository::getActiveShops()` ([`ChannelShopRepository.php:33-36`](Modules/Channel/app/Repositories/ChannelShopRepository.php:33)).

`getActiveShops()` mengembalikan **semua** toko `is_active = true` lintas channel. Loop TikTok kemudian memanggil `TikTokOrderService::pullOrders($shop->shop_id)` untuk **toko Lazada juga** → panggilan API TikTok memakai shop Lazada (cipher kosong) → gagal per-toko (tertangkap, tapi menghasilkan baris "error" yang menyesatkan), memboroskan kuota API, dan rawan rate-limit.

**Pembanding benar:** sisi Lazada memakai `getShopsByChannelCode('lazada')` lalu filter aktif ([`LazadaSyncApiController.php:67-68`](Modules/Channel/app/Http/Controllers/LazadaSyncApiController.php:67)).

**Usulan:** ganti `getActiveShops()` → `getShopsByChannelCode('tiktok')->filter(fn($s) => $s->is_active && $s->access_token)`.

---

### H-2. ~~`cancelProduct` TikTok selalu gagal — salah identifier~~ — DITARIK (false positive)
**Status:** ❌ **Bukan bug.** Ditarik setelah verifikasi lebih dalam pada 2026-06-12.

Temuan awal mengira `$order->channel_shop_id` adalah UUID internal `channel_shops.id`. Ternyata **tidak**:
- `TikTokToInternalOrderMapper::map` menyimpan **`shop_id` marketplace (eksternal)** ke kolom `sales_orders.channel_shop_id` ([`TikTokToInternalOrderMapper.php:45`](Modules/Channel/app/Services/TikTokToInternalOrderMapper.php:45)), dan `SalesOrderService::upsertFromChannel` menyimpannya apa adanya ([`SalesOrderService.php:301`](Modules/Sales/app/Services/SalesOrderService.php:301)).
- Jadi `findByShopId($order->channel_shop_id)` (query kolom `shop_id`) **cocok** dengan benar.
- `salesorder_no` = **TikTok order id** ([`TikTokToInternalOrderMapper.php:44`](Modules/Channel/app/Services/TikTokToInternalOrderMapper.php:44)), sehingga `order_id => $orderId` yang dikirim ke API TikTok juga benar.

Endpoint `POST /api/v1/tiktok/cancel-product` **berfungsi sebagaimana mestinya**; tidak ada perubahan yang dilakukan.

> Catatan utang teknis (bukan bug): nama kolom `channel_shop_id` menyimpan **id eksternal** di `sales_orders`, tetapi menyimpan **UUID internal** di `download_transactions`. Penamaan yang ambigu ini membingungkan dan layak diselaraskan di masa depan.

---

### H-3. Endpoint store & sync TikTok TIDAK terautentikasi — ✅ FIXED
Ditemukan saat menulis test regresi: grup `Route::prefix('v1/tiktok')` tidak memakai `auth:sanctum` sehingga semua store/sync publik.

**Perbaikan:** [`routes/api.php`](Modules/Channel/routes/api.php) — `webhook`, `auth`, `callback` tetap publik; **semua** sisanya (`cancel-reasons`, `cancel-product`, `stores*`, `auto-sync/*`, `sync/*`) dibungkus `Route::middleware('auth:sanctum')`. Pola kini setara Lazada.

Tes: `TikTokStoreApiTest::test_stores_require_auth` (401 tanpa login untuk index/show/destroy/refresh-token).

## 🟡 MEDIUM

### M-1. Pelanggaran arsitektur Service-Repository (`agents.md §1`) — ✅ FIXED
Query DB langsung di 3 service yang diaudit sudah dipindah ke Repository (`ChannelShopRepository::getIdByShopId`, `ChannelRepository::getIdByCode`, dan sejumlah metode baru di `ChannelProductRepository`: `getChannelCategoryExternalId`, `getProductSpecifications`, `getAttributeChannelMapping`, `getChannelAttribute`, `getAttributeOptionChannelMapping`, `getChannelAttributeOption`, `getChannelWarehouseByStore`, `getAvailableQty`, `getRawVariantOptions`, `getVariantByProductIdAndSku`). `TikTokProductService` kini bebas `DB::`.

> Residual (di luar scope M-1 awal): mapper (`TikTokToInternalProductMapper`, `LazadaToInternalProductMapper`, `LazadaProductMapper`) dan `LazadaAuthService`/`LazadaProductService` masih memuat query resolusi kategori via `DB::`/`Channel::`. Prioritas rendah; bisa dirapikan menyusul.

Detail sebelum perbaikan — Service yang melakukan query DB langsung:
- `ChannelDownloadService`: `ChannelShop::where('shop_id', ...)->value('id')` di [`ChannelDownloadService.php:59,93`](Modules/Channel/app/Services/ChannelDownloadService.php:59).
- `TikTokAuthService`: `Channel::where('code','tiktok')->value('id')` di [`TikTokAuthService.php:50`](Modules/Channel/app/Services/TikTokAuthService.php:50).
- `TikTokProductService`: banyak `DB::table(...)` langsung — `product_specifications`, `attribute_channel_mappings`, `channel_attributes`, `channel_warehouses`, `inventories`, `product_variants`, `channel_shops`, `products`, dan `Category::with(...)` di [`TikTokProductService.php:106-164,305,331-352,402-480`](Modules/Channel/app/Services/TikTokProductService.php:106).

**Usulan:** pindahkan query ke repository terkait (mis. `ChannelProductRepository`, repo atribut/warehouse). Bukan perbaikan bug, tapi utang teknis yang memicu duplikasi & sulit dites.

### M-2. Pelanggaran standar respons API (`agents.md §2`) — ✅ FIXED (API path)
Jalur API `ChannelController::index` kini memakai `ChannelResource::collection(...)` + `successPaginatedResponse` (resource baru `ChannelResource` membungkus `shops` via `ChannelShopResource`). Jalur `view()`/flash **sengaja dipertahankan** karena controller ini juga melayani halaman admin server-rendered (`web.php` → `channel::index`); memisahkannya menjadi controller web terpisah adalah follow-up tersendiri agar tidak merusak UI.

Detail awal:
- `ChannelController::index` memakai `view('channel::index')` dan `back()->with('error', ...)` di dalam controller yang juga melayani API ([`ChannelController.php:49,54`](Modules/Channel/app/Http/Controllers/ChannelController.php:49)). `disconnectShop`/`refreshShopToken` juga pakai flash session (`back()->with(...)`). `agents.md §2` melarang `view()`/`redirect()->route()`/flash session di controller API.
- Listing tidak konsisten: `TikTokStoreController::index` & `ChannelController::index` mengembalikan paginator mentah via `successResponse` (bukan `successPaginatedResponse` + Resource). Bandingkan dengan `DownloadTransactionController::index` yang sudah benar (map `->resolve()` lalu `successPaginatedResponse`).

**Usulan:** pisahkan controller web vs API, atau hapus jalur blade; selalu pakai Resource + `successPaginatedResponse` untuk listing.

### M-3. Pagination default ≠ 10 (`agents.md §5`) — ✅ FIXED
`paginateShopProducts` default → **10**, dan `DownloadTransactionController::show` kini memvalidasi `per_page`/`page`.

### M-4. N+1 + tanpa limit pada `ChannelDashboardService` (dead code) — ✅ FIXED (dihapus)
`ChannelDashboardService` dihapus beserta metode repo yatim (`getAllOrders`, `getOrderItems`, `getOrdersByChannelAndStatus`, `getAllProducts`) yang hanya dipakai olehnya. Tidak ada referensi lain (terverifikasi via grep).

Detail awal:
[`ChannelDashboardService::getDashboardData`](Modules/Channel/app/Services/ChannelDashboardService.php:19): `getAllOrders()` (tanpa pagination) → per order `getOrderItems()` → per item `getVariantBySku()` + `findById()`. Ini **N+1 bersarang** dan akan memuat seluruh tabel `sales_orders`/`products` (`getAllOrders`/`getAllProducts` tanpa limit, [`ChannelOrderRepository.php:25`](Modules/Channel/app/Repositories/ChannelOrderRepository.php:25), [`ChannelProductRepository.php:15`](Modules/Channel/app/Repositories/ChannelProductRepository.php:15)).

Saat ini **tidak ter-route / tidak dipakai** (dead code), jadi belum berdampak — tapi merupakan ranjau. **Usulan:** hapus jika tak terpakai, atau tulis ulang dengan eager-load + pagination sebelum diekspos.

### M-5. Urutan null-guard salah di `SyncProductToChannelJob` — ✅ FIXED
Guard `if (!$product || !$shop) return;` dipindah ke atas, sebelum loop override harga yang mengakses `$shop->id`.

Detail awal:
[`SyncProductToChannelJob::handle`](Modules/Channel/app/Jobs/SyncProductToChannelJob.php:63): loop override harga mengakses `$shop->id` (baris 71) **sebelum** guard `if (!$product || !$shop)` (baris 80). Jika `$shop` null (mis. toko terhapus) tetapi `$product` ada → "Attempt to read property 'id' on null" → job exception/retry.

**Usulan:** pindahkan guard `!$product || !$shop` ke atas, sebelum loop variant.

### M-6. `pushProduct` menyuntik gambar placeholder + fetch gambar — ✅ FIXED (terintegrasi Spatie/R2)
- Fallback base64 dummy dihapus.
- Upload gambar TikTok kini lewat dua service baru:
  - `ChannelMediaResolver` — resolusi referensi media **UUID-first** sesuai pola universal `POST /api/v1/media/upload`: (1) jika referensi adalah **UUID media terpusat** → `UploadService::findByUuid` lalu baca byte dari disk Spatie (Cloudflare R2/S3); (2) jika URL menunjuk ke disk media → baca dari disk; (3) fallback HTTP `timeout(10)` untuk URL eksternal (gambar hasil download marketplace). Tidak ada lagi `@file_get_contents` mentah. Forward-compatible: bekerja baik saat `product_media` menyimpan UUID maupun URL.
  - `TikTokImageUploader` — unggah byte ke `/product/202309/images/upload`, gambar gagal di-skip (log), tidak menggagalkan seluruh push, tidak pernah unggah dummy.
- Di-wire ke **dua jalur push**: `TikTokProductService::pushProduct` (legacy) **dan** `TikTokAdapter::pushProduct` (jalur kanonik yang sebelumnya **tidak meng-upload gambar sama sekali** — loop kosong).
- Tes: `TikTokImageUploadTest` (resolver baca via UUID media, baca dari disk R2, fallback HTTP, return null saat unreachable; uploader sukses & skip).

> Follow-up modul Product (di luar Channel): idealnya `product_media` menyimpan **UUID media** (hasil `/api/v1/media/upload`) sebagai referensi kanonik, bukan hanya `url`. Resolver sudah siap untuk itu (UUID-first).

Detail awal:
[`TikTokProductService::pushProduct`](Modules/Channel/app/Services/TikTokProductService.php:55): jika `@file_get_contents($m->url)` gagal, kode memakai **base64 JPEG dummy hardcoded** lalu tetap meng-upload. Akibatnya produk bisa terkirim ke TikTok dengan gambar sampah alih-alih gagal/di-skip.

Tambahan: `@file_get_contents($m->url)` adalah fetch remote di jalur request (blocking, tanpa validasi URL → potensi SSRF & latensi tinggi).

**Usulan:** jika gambar gagal diambil → catat error & lewati/gagalkan produk; pindahkan fetch ke job async dengan validasi host.

---

## 🟢 LOW / Hygiene

### L-1. Perbandingan signature webhook TikTok tidak constant-time — ✅ FIXED
`TikTokWebhookController::handle` kini memakai `hash_equals($calculatedSignature, (string) $signature)`.

### L-2. Logging & endpoint debug membocorkan rahasia — ✅ FIXED
- Log webhook & callback TikTok tidak lagi memuat header/body penuh — hanya `type`/`shop_id`.
- Method `callbackDebug` + route `GET /v1/tiktok/callback-debug` dihapus (terverifikasi via `route:list`).

Detail awal:
- `TikTokAuthController::callback` mem-`Log::info` **seluruh header + body** ([`TikTokAuthController.php:27-32`](Modules/Channel/app/Http/Controllers/TikTokAuthController.php:27)).
- `TikTokWebhookController::handle` mem-log seluruh header + body ([`TikTokWebhookController.php:19-22`](Modules/Channel/app/Http/Controllers/TikTokWebhookController.php:19)).
- `TikTokAuthController::callbackDebug` (route `GET /v1/tiktok/callback-debug`) membaca & mengembalikan isi `laravel.log` ([`TikTokAuthController.php:72`](Modules/Channel/app/Http/Controllers/TikTokAuthController.php:72)). Diberi guard non-production, tapi ini permukaan debug yang harus **dihapus** setelah verifikasi (komentar di kodenya sendiri menyatakan demikian).

**Usulan:** redaksi header sensitif sebelum log; hapus route + method `callback-debug`.

### L-3. `bulkPush` mengembalikan HTTP 500 untuk kegagalan parsial — ✅ FIXED
Kini membalas **200** `successResponse(['fail_count' => N], ...)` dengan pesan yang menyesuaikan; 500 hanya untuk exception sejati.

### L-4. Use case webhook TikTok belum ditangani — ✅ FIXED (di-acknowledge)
Tipe `4` & `5` tidak lagi no-op senyap: kini `Log::info` eksplisit "belum ada handler khusus" + `shop_id`. (Implementasi handler sesungguhnya menunggu spesifikasi event TikTok — follow-up.)

### L-5. Risiko loop sinkronisasi stok — ✅ FIXED
`SyncStockToChannelsJob` menerima param opsional `excludeChannelShopId`; `WebhookProductHandler::handleProductUpdate` mengoper `channel_shop_id` toko asal sehingga push-back **tidak** dikirim balik ke channel sumber.

### L-6. Inkonsistensi `downloadBulk` — ✅ FIXED
`ChannelDownloadService::downloadBulk` membungkus tiap toko dalam `try/catch` (skip + `Log::warning`), jadi satu toko bermasalah tidak lagi menggagalkan seluruh batch / memicu 500. Kontrak respons (`data` = daftar transaksi ter-queue) dipertahankan.

### L-7. Catatan kecil lain
- ✅ Docblock `ChannelController::disconnectShop` diperbaiki ("deactivate + clear tokens", bukan "soft-delete").
- ℹ️ `DownloadTransactionController::show` menampilkan **semua** produk toko berstatus `download`/`in_review`, bukan khusus hasil transaksi terkait — dibiarkan; verifikasi dengan product owner apakah memang diinginkan.
- ℹ️ File `routes.json` untracked di root repo (di luar modul) — pastikan disengaja / masuk `.gitignore`. Tidak diubah.

---

## Planning / Rencana Perbaikan

Urutan dirancang agar perbaikan berisiko-rendah & berdampak-tinggi didahulukan, dan saling tidak memblokir.

### Fase 1 — Stop the bleeding (CRITICAL, ~0.5 hari)
1. **C-1**: `int $id` → `string $id` di TikTokStoreController + TikTokAuthService + ChannelShopRepository (+ ChannelController web), dan `->whereUuid('id')` pada route `tiktok/stores/{id}`.
2. **C-2**: tambah `$hidden` pada `ChannelShop`; ubah `TikTokStoreController::index` & `ChannelController::index` agar memakai `ChannelShopResource` + `successPaginatedResponse`.
3. Tambah **feature test**: hit ketiga endpoint store TikTok dengan UUID nyata (harus 200/404, bukan 500) + assert respons tidak memuat `access_token`.

### Fase 2 — Logika bisnis rusak (HIGH, ~0.5–1 hari)
4. **H-1**: filter auto-sync TikTok ke channel `tiktok` saja.
5. **H-2**: perbaiki resolusi shop & order id pada `cancelProduct` + test regresi.

### Fase 3 — Kepatuhan `agents.md` & ketahanan (MEDIUM, ~1–2 hari)
6. **M-2/M-3**: rapikan controller API (hapus `view()`/flash), konsisten `successPaginatedResponse` + Resource, pagination default 10.
7. **M-5**: pindahkan null-guard di `SyncProductToChannelJob`.
8. **M-6**: tangani kegagalan ambil gambar (skip/fail), pindah fetch ke job + validasi.
9. **M-1**: refactor query DB dari Service ke Repository (bisa bertahap per service).
10. **M-4**: hapus/atau tulis-ulang `ChannelDashboardService` (dead code N+1).

### Fase 4 — Hygiene & keamanan (LOW, ~0.5 hari)
11. **L-1** `hash_equals` untuk webhook TikTok.
12. **L-2** redaksi log sensitif + hapus route/method `callback-debug`.
13. **L-3/L-6** status code & penanganan parsial untuk `bulkPush`/`downloadBulk`.
14. **L-4/L-5/L-7** dokumentasi/implementasi use case webhook, mitigasi loop stok, dan pembersihan dokumentasi.

### Catatan pengujian
- Belum ada test untuk store TikTok (folder `tests/Feature` fokus ke Lazada & download). Tambah cakupan untuk C-1, C-2, H-1, H-2.
- Jalankan suite via `rtk` (mis. `rtk test php artisan test --filter=Channel`) sesuai konvensi proyek.

---

## Lampiran — Inventaris endpoint & status cepat

| Endpoint | Controller | Status temuan |
|---|---|---|
| `GET /v1/marketplace/store` | ChannelController::stores | OK (Resource aman) |
| `GET /v1/channels` (apiResource index) | ChannelController::index | C-2 (leak), M-2 (view/flash) |
| `GET /v1/tiktok/stores` | TikTokStoreController::index | C-2 (leak token) |
| `GET/DELETE /v1/tiktok/stores/{id}`, `…/refresh-token` | TikTokStoreController | **C-1 (500)** |
| `POST /v1/tiktok/cancel-product` | TikTokSyncApiController::cancelProduct | OK (H-2 ditarik — false positive) |
| `POST /v1/tiktok/auto-sync/pull-*` | TikTokSyncApiController | ✅ H-1 fixed (filter channel tiktok) |
| `POST /v1/tiktok/sync/*` | TikTokSyncApiController | Handled (500 hanya saat error API, tertangkap) |
| `POST /v1/tiktok/webhook` | TikTokWebhookController | L-1, L-2 |
| `GET /v1/tiktok/callback-debug` | TikTokAuthController | L-2 (hapus) |
| `GET /v1/lazada/*` (semua) | Lazada* | OK (referensi pola benar) |
| `POST /v1/{channel}/download[/bulk]` | ChannelDownloadController | L-6 (bulk parsial) |
| `GET /v1/download-transactions[/{id}]` | DownloadTransactionController | M-3 (per_page 25) |
</content>
</invoke>
