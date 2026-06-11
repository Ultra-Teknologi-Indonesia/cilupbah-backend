# PLAN — Lazada Omnichannel: Integrasi Lengkap

**Domain:** Omnichannel · **PIC:** Darriel · **Modul:** `Modules/Channel` (+ titik sentuh Sales/Product/Inventory milik Rasyid — read-only/via service resmi)
**Prasyarat (✅ selesai):** OAuth callback Lazada (`LazadaClient`, `LazadaAuthService`, `/v1/lazada/auth` + `/v1/lazada/callback`), token tersimpan di `channel_shops` (channel `lazada`).

## 0. Peta item tracker (11 item Omnichannel Lazada)

| # | Item tracker | Status | Fase di plan ini |
|---|---|---|---|
| 1 | Lazada — OAuth / Auth toko | **done** (kemarin) | — |
| 2 | Lazada — Manajemen toko (list/refresh token) | todo | Fase 1 |
| 3 | Lazada — Tarik order (pull) | todo | Fase 4 |
| 4 | Lazada — Terima/tolak order | todo | Fase 5 |
| 5 | Lazada — Push produk (create listing) | todo | Fase 3 |
| 6 | Lazada — Sync produk (update) | todo | Fase 3 |
| 7 | Lazada — Sync stok (push balik) | todo | Fase 3 |
| 8 | Lazada — Sync harga | todo | Fase 3 |
| 9 | Lazada — Webhook masuk | todo | Fase 6 |
| 10 | Lazada — Cancel order | todo | Fase 5 |
| 11 | Lazada — Logistik / kurir channel | todo | Fase 5 |

Bonus terkait (domain Reports, sudah ada stub route): `GET /lazada/get-document` (label pengiriman) → Fase 5.

---

## 1. Prinsip arsitektur (mengikuti blueprint TikTok yang ada)

1. **Adapter pattern**: implement `MarketplaceAdapterInterface` → `LazadaAdapter`; daftarkan di `AdapterFactory` (`'lazada' => app(LazadaAdapter::class)`). Semua job generik (`SyncProductToChannelJob`, `SyncStockToChannelsJob`, `DownloadProductsJob`) otomatis bisa melayani Lazada **tanpa mengubah job-nya**.
2. **Order masuk = SalesOrder**: order Lazada diubah jadi `SalesOrder` lewat `Modules\Sales\Services\SalesOrderService` (persis `TikTokOrderService`) → stock locking (`lockForUpdate` + `afterCommit`) otomatis terjaga karena lewat service resmi Rasyid, bukan tulis tabel langsung.
3. **Tidak ada queue baru**: pakai queue yang sudah disupervisi Horizon (`channel-sync`, `orders`, `downloads`, `default`). **Box staging 4GB baru saja di-cap 9 worker** — menambah queue baru = butuh supervisor baru = RAM. Webhook Lazada masuk ke queue `default` (seperti `tiktok-webhooks` yang dilayani supervisor-default).
4. **No-500**: callback/webhook publik selalu balas 2xx/4xx terkontrol; signature invalid → 401; payload aneh → log + 200 (agar Lazada tidak retry-storm); id non-UUID → 404 via `whereUuid`.
5. **Token Lazada cepat kedaluwarsa** (access ±7 hari, refresh ±30 hari) → wajib ada **auto-refresh terjadwal** (TikTok belum punya ini; untuk Lazada ini kritis).

## 2. API Lazada yang dipakai (referensi open.lazada.com)

| Kebutuhan | API Lazada | Catatan |
|---|---|---|
| Refresh token | `/auth/token/refresh` (auth.lazada.com) | ✅ sudah ada di `LazadaClient` |
| Info seller | `/seller/get` | validasi koneksi toko |
| Pull order | `/orders/get` (+`/order/items/get`) | filter `update_after`, status |
| Pack / RTS (≈ "terima order") | `/order/fulfill/pack`, `/order/package/rts` | Lazada tidak punya accept/reject eksplisit — semantiknya **pack → ready_to_ship** |
| Cancel order | `/order/cancel` | butuh `cancel_reason_id` (`/order/reverse/cancel/validate` opsional) |
| Push produk | `/product/create` | payload XML/JSON `Request.Product` |
| Update produk | `/product/update` | |
| Sync harga & stok | `/product/price_quantity/update` | satu API untuk dua kebutuhan |
| Pull produk | `/products/get` | untuk download/mapping awal |
| Kurir/logistik | `/shipment/providers/get` | item #11 |
| Label/dokumen | `/order/document/get` | untuk `GET /lazada/get-document` (Reports) |
| Brand/kategori | `/category/tree/get`, `/category/attributes/get` | dukungan push produk |

Semua lewat **`LazadaClient::request()` baru** (Fase 2): signed call ke `LAZADA_BASE_URL` (region ID) dengan `access_token` sebagai param + signature HMAC-SHA256 yang sudah teruji.

---

## 3. Fase implementasi

### Fase 1 — Manajemen toko + auto-refresh token (item #2)
**File:**
- `app/Http/Controllers/LazadaStoreController.php` — mirror `TikTokStoreController`: `index/show/destroy/refreshToken`.
- `LazadaAuthService`: tambah `getStores()`, `getStoreDetail()`, `disconnectStore()`, `refreshStoreToken()` (pola TikTok; `getTokenStatus` reuse).
- `ChannelShopRepository`: `getShopsByChannelCode(string $code)` (generalisasi `getAllTikTokShops` — tanpa mengubah method lama).
- **Scheduler**: command `lazada:refresh-tokens` (refresh semua toko Lazada yang `token_expires_at` < 48 jam) + jadwal daily di console routing. Idempoten, log hasil, tidak melempar (no-500 di scheduler).
- Routes: `GET/DELETE /v1/lazada/stores...`, `POST /v1/lazada/stores/{id}/refresh-token` (auth:sanctum).

**Test:** list/detail/disconnect/refresh (Http::fake), token expired → status `expired`, refresh gagal → 422 bukan 500.

### Fase 2 — `LazadaClient::request()` + `LazadaAdapter` kerangka
**File:**
- `LazadaClient`: method `request(string $method, string $apiPath, array $params = [], array $body = [], ?string $accessToken = null)` → sign + kirim ke `config('services.lazada.base_url')`; throttle `RateLimiter` (pola `TikTokClient::throttle`, key `lazada-api`); deteksi error token (`IllegalAccessToken`) → lempar `TokenExpiredException` (exception yang sudah ada).
- `app/Adapters/LazadaAdapter.php` implements `MarketplaceAdapterInterface` (7 method) — kerangka + `getChannelCode(): 'lazada'`.
- `AdapterFactory`: tambah case `'lazada'`.
- `ChannelDownloadService::assertSupported`: izinkan `lazada`.

**Test:** signature request bisnis, error-mapping token expired, AdapterFactory resolve lazada.

### Fase 3 — Produk: push, update, harga+stok, pull (item #5,6,7,8)
**Mapper:**
- `LazadaProductMapper` (internal → payload Lazada: atribut wajib, SellerSku = `variant.sku`, gambar dari URL media R2).
- `LazadaToInternalProductMapper` (Lazada → draft internal; pola `TikTokToInternalProductMapper`).

**Adapter (isi nyata):**
- `pushProduct` → `/product/create`; simpan `external_product_id` (item_id Lazada) ke channel mapping (struktur `product.channelMappings` yang sudah dipakai TikTok).
- `updateProduct` → `/product/update`.
- `syncPriceAndStock` → `/product/price_quantity/update` (dipicu otomatis oleh `SyncStockToChannelsJob` yang SUDAH berjalan saat stok berubah — zero perubahan di job).
- `activate/deactivateProduct` → `/product/item/activate|deactivate` (atau update status).
- `deleteProduct` → `/product/remove`.
- `mapInboundProduct` → delegasi mapper inbound.

**Pull produk:** `DownloadProductsJob` (existing) + `ChannelDownloadService::pull` cabang lazada → `/products/get` paginasi → mapper inbound → draft produk (alur sama TikTok). Endpoint generik `POST /v1/lazada/download` **sudah ada** (route `{channel}/download`).

**Test:** mapper dua arah (fixture payload Lazada), adapter push/update/price-stock dengan Http::fake, mapping disimpan benar.

### Fase 4 — Tarik order → SalesOrder (item #3)
**File:**
- `LazadaOrderService` — mirror `TikTokOrderService`: `pullOrders(shop, since)` → `/orders/get` + `/order/items/get` → `LazadaToInternalOrderMapper` → `SalesOrderService` (stock locking aman, idempoten via `channel_order_id` unik — cek pola dedup TikTok).
- `LazadaSyncApiController`: `POST /v1/lazada/sync/pull` (per toko) + `POST /v1/lazada/auto-sync/pull-orders` (semua toko aktif) — mirror TikTok. Queue `orders`.
- Mapping status Lazada → status internal SalesOrder: `unpaid/pending→new`, `packed/ready_to_ship→to_ship`, `shipped→shipped`, `delivered→done`, `canceled→cancelled`.

**Test:** pull membuat SalesOrder + item + reserved stock via service resmi; pull ulang tidak duplikat; order cancel di Lazada → status update.

### Fase 5 — Operasi order & logistik (item #4, #10, #11 + get-document)
- **"Terima order"** (semantik Lazada): `POST /v1/lazada/sync/pack` → `/order/fulfill/pack` lalu `/order/package/rts`. **"Tolak"** = cancel.
- **Cancel:** `POST /v1/lazada/sync/cancel` (+ `GET /v1/lazada/cancel-reasons` jika tersedia API reason).
- **Logistik/kurir:** `GET /v1/lazada/logistics` → `/shipment/providers/get` (pola `GET /shopee/logistics` & `couriers` yang ada).
- **Label:** implement stub Reports `GET /lazada/get-document` → `/order/document/get` (koordinasi: route ada di modul Report — isi controller-nya memanggil `LazadaClient`, tanpa ubah struktur Report lain).

**Test:** pack/rts/cancel happy-path + error Lazada → 422; logistics list; get-document.

### Fase 6 — Webhook lengkap: inbound Lazada + outbound internal (item #9)

**6a. Inbound (Lazada → kita):** `POST /v1/lazada/webhook` (publik) — `LazadaWebhookController@handle`:
  1. Verifikasi signature push Lazada: `HMAC-SHA256(app_secret, app_key + timestamp + rawBody)` — bandingkan constant-time (`hash_equals`); invalid → 401.
  2. Balas cepat 200, proses async: `ProcessLazadaWebhook` job (queue `default`, pola `ProcessTikTokWebhook`).
  3. **Event types Lazada (message_type)** yang ditangani:
     | message_type | Event | Aksi |
     |---|---|---|
     | 0 | Order status changed | pull order tunggal → create/update SalesOrder |
     | 1 | Product QC/audit result | update `sync_status` channel mapping (approved/rejected + alasan) |
     | 2 | Product item changed (delist dsb) | update status mapping |
     | lainnya (penalty, IM, dsb) | — | log + abaikan (200) |
  4. Dedup event: simpan `message_id` terakhir per shop (cache) → event duplikat diabaikan.
- Daftarkan URL push di console Lazada: `https://staging.ultra-fit.id/api/v1/lazada/webhook`.

**6b. Outbound (kita → subscriber internal, modul Webhook yang sudah dibangun):**
- **Otomatis tersambung tanpa kode baru**: order Lazada masuk via `SalesOrderService` → `SalesOrderWebhookObserver` memancarkan event `salesorder`; perubahan stok akibat reservasi → event `stock`; dst. Semua 9 observer existing berlaku untuk data yang berasal dari Lazada.
- ⚠️ **Fix prasyarat (celah ditemukan saat audit):** job outbound webhook diarahkan ke queue `webhooks` (`Modules/Webhook/config/config.php`), tapi **tidak ada supervisor Horizon yang melayani queue itu** → webhook outbound tidak pernah terkirim. **Fix:** tambahkan `'webhooks'` ke daftar queue `supervisor-default` di `config/horizon.php` (tanpa worker/supervisor baru — aman untuk box 4GB). Sertakan di fase ini.

**Test:** signature valid→200+job ter-enqueue, invalid→401, payload tak dikenal→200 tanpa job, dedup message_id, order-status-event memperbarui SalesOrder, **outbound `salesorder` ter-enqueue ke queue yang disupervisi**, tidak pernah 500.

### Fase 7 — Tracker + dokumentasi
- Update 10 item Omnichannel Lazada → `done` (DB + seeder + generator ST dict).
- Catat env/konfigurasi yang dibutuhkan ops (lihat §5).

---

## 4. Integrasi & risiko

| Aspek | Penanganan |
|---|---|
| **Stock locking** | Order → `SalesOrderService` (lockForUpdate); push stok keluar → `SyncStockToChannelsJob` existing yang dispatch `afterCommit` — tidak ada jalur tulis stok baru |
| **Modul Rasyid** | Tidak diubah; hanya konsumsi service resmi (SalesOrderService) + struktur channelMappings yang sudah dipakai TikTok |
| **RAM/Horizon staging (4GB, 9 worker)** | Tidak ada queue/supervisor baru; semua job ke queue existing; scheduler refresh-token 1x/hari ringan |
| **Rate limit Lazada** | Throttle `RateLimiter('lazada-api')` per detik (config `channel.api_rate_limit_per_second` reuse) |
| **Token expiry 7 hari** | `lazada:refresh-tokens` harian + retry saat `TokenExpiredException` (refresh lalu ulang sekali — pola TikTok) |
| **Idempotensi order** | Dedup via `channel_order_id`/`salesorder_no` unik sebelum create |
| **route:cache** | Semua route diberi nama unik `lazada.*` |

## 5. Konfigurasi/ENV (sudah ada sebagian)
```
LAZADA_APP_KEY=…            ✅ terisi
LAZADA_APP_SECRET=…         ✅ terisi
LAZADA_REDIRECT_URI=https://staging.ultra-fit.id/api/v1/lazada/callback  ✅
LAZADA_AUTH_URL=https://auth.lazada.com   ✅
LAZADA_BASE_URL=https://api.lazada.co.id/rest  ✅ (region ID)
```
Tambahan: daftarkan **push/webhook URL** di console Lazada saat Fase 6.

## 6. Urutan eksekusi & estimasi effort

| Fase | Item tracker | Effort relatif | Dependensi |
|---|---|---|---|
| 1. Store mgmt + refresh | #2 | S | — |
| 2. Client request + Adapter kerangka | (infra) | S | F1 |
| 3. Produk (push/update/harga/stok/pull) | #5,6,7,8 | **L** | F2 |
| 4. Pull order → SalesOrder | #3 | M | F2 |
| 5. Pack/RTS, cancel, logistik, label | #4,10,11 | M | F4 |
| 6. Webhook masuk | #9 | S–M | F4 |
| 7. Tracker & dokumentasi | — | XS | semua |

Rekomendasi batch kerja: **F1+F2 → commit**, **F4 (order dulu — nilai bisnis tertinggi: order masuk otomatis) → commit**, **F3 → commit**, **F5+F6 → commit**, F7 penutup. Order didahulukan daripada produk karena pull order tidak bergantung mapping produk lengkap (SKU di-resolve best-effort, pola TikTok).

## 7. Definition of Done (keseluruhan)
- [ ] 10 item tracker Omnichannel Lazada → done (OAuth sudah)
- [ ] Order Lazada otomatis masuk sebagai SalesOrder (pull + webhook), tanpa duplikat, stok ter-reserve via service resmi
- [ ] Perubahan stok/harga internal otomatis ter-push ke Lazada (via job existing)
- [ ] Push/update produk + pull produk jalan
- [ ] Pack/RTS, cancel, logistik, get-document jalan
- [ ] Token auto-refresh harian
- [ ] Semua test hijau (Http::fake — tanpa panggil Lazada nyata), no-500, `route:cache` OK, tidak ada queue/worker baru
- [ ] Commit per fase + merge main
