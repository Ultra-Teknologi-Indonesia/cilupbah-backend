# PLAN — Integrasi Shopee (Phase 1: OAuth Callback + Webhook)

Status: DRAFT • Modul: `Modules/Channel` • Acuan: integrasi TikTok & Lazada yang sudah ada.

Tujuan Phase 1 (sesuai scope yang diminta): membangun **alur OAuth (auth + callback)** dan
**penerimaan webhook/push** Shopee, setara dengan yang sudah berjalan untuk TikTok & Lazada.
Sinkronisasi produk/order/stock adalah fase berikutnya (di luar scope Phase 1).

---

## 1. Analisa: Pola yang Sudah Ada (acuan)

Integrasi marketplace di repo ini sudah punya **pola baku** di `Modules/Channel`. Setiap channel
memakai komponen yang sama:

| Komponen | TikTok | Lazada | Shopee (akan dibuat) |
|---|---|---|---|
| OAuth controller | `TikTokAuthController` | `LazadaAuthController` | `ShopeeAuthController` |
| Webhook controller | `TikTokWebhookController` | `LazadaWebhookController` | `ShopeeWebhookController` |
| HTTP client (sign + call) | `TikTokClient` | `LazadaClient` | `ShopeeClient` |
| Auth service (exchange/refresh) | `TikTokAuthService` | `LazadaAuthService` | `ShopeeAuthService` |
| Webhook job (async) | `ProcessTikTokWebhook` | `ProcessLazadaWebhook` | `ProcessShopeeWebhook` |
| Signature helper | `TikTokSignature` | (inline di client) | `ShopeeSignature` |
| Adapter | `TikTokAdapter` | `LazadaAdapter` | `ShopeeAdapter` (fase lanjut) |
| Routes | `routes/api.php` prefix `v1/tiktok` | prefix `v1/lazada` | prefix `v1/shopee` |

Infrastruktur bersama yang **dipakai ulang tanpa perubahan**:
- `Modules\Channel\Support\OAuthFlow` — issue/consume `state` (anti-CSRF, TTL 10 menit, via Cache)
  dan bangun `frontendUrl()` redirect ke `/dashboard/integrasi-channel`.
- Tabel `channels` — record `shopee` **sudah ada** di `ChannelDatabaseSeeder` (tidak perlu seeder baru).
- Tabel `channel_shops` — sudah punya kolom `shop_id`, `shop_name`, `shop_cipher`, `access_token`,
  `refresh_token`, `token_expires_at`, `refresh_token_expires_at`, `is_active`, `disconnected_at`,
  + kolom integration-health. **Cukup untuk Shopee → tidak perlu migrasi baru di Phase 1.**
- `ChannelShopRepository` — `updateOrCreateShop()`, `findByUuid()`, `getShopsByChannelCode()`,
  `markIntegrationHealthy/Error()`.
- `App\Traits\ApiResponse` — `successResponse()` / `errorResponse()`.

**Kesimpulan analisa:** menambah Shopee = mengikuti template Lazada, dengan menukar bagian yang
**spesifik Shopee**: skema signature, endpoint OAuth, dan format push. Tidak ada perubahan skema DB.

---

## 2. Perbedaan Kunci Shopee vs Lazada/TikTok (yang harus diperhatikan)

> Verifikasi final terhadap Shopee Open Platform docs saat implementasi — angka & path di bawah
> berdasarkan API v2 publik.

1. **Kredensial bernama beda**: Shopee pakai `partner_id` + `partner_key` (bukan `app_key`/`app_secret`).
2. **Signature**:
   - API publik (mis. token get/refresh): `HMAC-SHA256( partner_id + api_path + timestamp , partner_key )`.
   - API level-shop: `HMAC-SHA256( partner_id + api_path + timestamp + access_token + shop_id , partner_key )`.
   - Output **hex lowercase** (Lazada uppercase) → jangan menyalin format Lazada mentah-mentah.
3. **Auth URL**: `GET {host}/api/v2/shop/auth_partner?partner_id=..&timestamp=..&sign=..&redirect=..`.
   `timestamp` yang dipakai untuk `sign` **harus sama persis** dengan yang ada di URL.
4. **Callback mengirim `shop_id`**: Shopee redirect balik dengan `?code=...&shop_id=...`
   (kadang `main_account_id` untuk multi-shop). **Berbeda dari Lazada yang hanya `code`** —
   `handleCallback()` Shopee **butuh `code` DAN `shop_id`**.
5. **Token exchange**: `POST {host}/api/v2/auth/token/get` body `{ code, shop_id, partner_id }`.
   Access token **berlaku 4 jam** (pendek!), refresh token **30 hari**.
   → refresh terjadwal lebih agresif dari Lazada (lihat §7).
6. **Webhook = "Push Mechanism"**: URL push diset di Console Shopee. Shopee `POST` JSON dengan
   field `shop_id`, `code` (tipe push, mis. order status), `data`, `timestamp`.
   **Signature push**: `HMAC-SHA256( push_url + "|" + raw_body , partner_key )` di header
   `Authorization`. (Beda dari TikTok yang `app_key+body`, dan Lazada `app_key+body`.)
7. **Host per region**: produksi `https://partner.shopeemobile.com`,
   sandbox `https://partner.test-stable.shopeemobile.com`. Buat `host` configurable.

---

## 3. Berkas yang Dibuat / Diubah (Phase 1)

### Baru
```
Modules/Channel/app/Http/Controllers/ShopeeAuthController.php
Modules/Channel/app/Http/Controllers/ShopeeWebhookController.php
Modules/Channel/app/Services/ShopeeClient.php
Modules/Channel/app/Services/ShopeeAuthService.php
Modules/Channel/app/Helpers/ShopeeSignature.php
Modules/Channel/app/Jobs/ProcessShopeeWebhook.php
Modules/Channel/tests/Feature/ShopeeAuthTest.php
Modules/Channel/tests/Feature/ShopeeWebhookTest.php
```

### Diubah
```
config/services.php                 → tambah blok 'shopee' (partner_id, partner_key, host, redirect_uri)
.env / .env.example                 → SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY, SHOPEE_HOST, SHOPEE_REDIRECT_URI
Modules/Channel/routes/api.php       → tambah grup prefix 'v1/shopee'
Modules/Channel/app/Adapters/AdapterFactory.php → tambah case 'shopee' (stub; adapter penuh = fase lanjut)
```

Tidak ada migrasi baru. `channels.shopee` sudah di-seed.

---

## 4. Rincian Implementasi

### 4.1 Config — `config/services.php`
```php
'shopee' => [
    'partner_id'   => env('SHOPEE_PARTNER_ID'),
    'partner_key'  => env('SHOPEE_PARTNER_KEY'),
    'host'         => env('SHOPEE_HOST', 'https://partner.shopeemobile.com'),
    'redirect_uri' => env('SHOPEE_REDIRECT_URI'),
],
```

### 4.2 `ShopeeSignature` helper
- `authPartnerSign(partnerId, path, timestamp)` → publik (untuk auth URL & token get/refresh).
- `shopSign(partnerId, path, timestamp, accessToken, shopId)` → level-shop (fase lanjut).
- `pushSign(pushUrl, rawBody)` → validasi webhook.
- Semua `hash_hmac('sha256', $base, $partnerKey)` lowercase, dibandingkan dengan `hash_equals`.

### 4.3 `ShopeeClient`
- Konstruktor baca `partner_id/partner_key/host`, lempar `RuntimeException` jika kosong
  (mirror `LazadaClient`).
- `getAuthUrl(redirectUri, state)` → bangun `/api/v2/shop/auth_partner` + sign + sisipkan `state`
  di `redirect` URI (Shopee meneruskan query redirect apa adanya).
- `getAccessToken(code, shopId)` → `POST /api/v2/auth/token/get`.
- `refreshAccessToken(refreshToken, shopId)` → `POST /api/v2/auth/access_token/get`.
- Throttle via `RateLimiter` (samakan dengan pola `LazadaClient::throttle`).

### 4.4 `ShopeeAuthController` (mirror `LazadaAuthController`)
- `redirect()` → `OAuthFlow::issueState('shopee')` → `successResponse(['auth_url' => ...])`.
- `callback(Request)`:
  - tanpa `code`/`state` → balas `service ready` (untuk health-check Console).
  - `OAuthFlow::consumeState($state,'shopee')` gagal → `invalid_state`.
  - **ambil `shop_id` dari query** → panggil `ShopeeAuthService::handleCallback($code, $shopId)`.
  - sukses/gagal → `finish()` redirect ke frontend (reuse pola Lazada).

### 4.5 `ShopeeAuthService::handleCallback(string $code, string $shopId)`
- `token = client->getAccessToken($code, $shopId)`; validasi `access_token` ada.
- `channelId = Channel::where('code','shopee')->value('id')`.
- `shopRepository->updateOrCreateShop($shopId, [... access_token, refresh_token,
  token_expires_at = now()+expire_in (≈4 jam), refresh_token_expires_at, is_active=true ...])`.
- (Opsional) panggil `/api/v2/shop/get_shop_info` untuk `shop_name`; jika belum, default `"Shopee {shopId}"`.
- `getStores/getStoreDetail/disconnectStore/refreshStoreToken/getTokenStatus` → salin pola Lazada.

### 4.6 `ShopeeWebhookController` (mirror Lazada)
- `verify()` (GET) → `service ready` untuk validasi URL di Console.
- `handle()` (POST):
  - `rawBody = $request->getContent()`.
  - validasi `pushSign(full_request_url, rawBody)` vs header `Authorization` → gagal = 401.
  - `json_decode`; bukan array → 200 OK (abaikan, jangan retry-storm).
  - **idempotensi**: `Cache::add('shopee:webhook:'.md5(shop_id|code|timestamp|data_id), 1, 1 hari)`
    → duplikat = 200 OK tanpa proses (pola `isFirstDelivery` Lazada).
  - `ProcessShopeeWebhook::dispatch($payload)->onQueue(...)`; balas 200 cepat.
- Penting: Shopee meng-anggap non-2xx sebagai gagal & retry → **selalu balas 200** kecuali signature invalid.

### 4.7 `ProcessShopeeWebhook` job (Phase 1: routing + log saja)
- `tries=3`, `backoff=[10,60,300]`, `failed()` log permanen (pola Lazada).
- `match ($payload['code'])` → tipe push Shopee (mis. `3` = order status, `4` = tracking,
  `5/6` = shop/item update, `12` = token expiry-ish). **Phase 1 cukup log + TODO handler**;
  pemanggilan service order/produk menyusul di fase sinkronisasi.
- Sediakan hook `handleTokenExpiry()` → `ShopeeAuthService::refreshStoreToken()` (karena token 4 jam).

### 4.8 Routes — `Modules/Channel/routes/api.php`
```php
Route::prefix('v1/shopee')->group(function () {
    Route::get('auth', [ShopeeAuthController::class, 'redirect'])->name('shopee.auth');
    Route::get('callback', [ShopeeAuthController::class, 'callback'])->name('shopee.callback');
    Route::get('webhook', [ShopeeWebhookController::class, 'verify'])->name('shopee.webhook.verify');
    Route::post('webhook', [ShopeeWebhookController::class, 'handle'])->name('shopee.webhook');

    Route::middleware('auth:sanctum')->group(function () {
        // stores index/show/destroy/refresh-token — mirror Lazada (boleh ikut Phase 1 kalau sempat)
    });
});
```
Catatan: `auth`, `callback`, `webhook` **tanpa `auth:sanctum`** (dipanggil Shopee/browser),
sama seperti TikTok & Lazada.

---

## 5. Keamanan (wajib, sudah jadi standar di repo)

- **Verifikasi signature** sebelum proses apa pun (401 jika gagal) — push & token.
- **`state` anti-CSRF** pada OAuth via `OAuthFlow` (sudah ada).
- **Idempotensi webhook** via Cache (hindari proses ganda saat Shopee retry).
- **Jangan bocorkan token**: pastikan resource `stores` tidak mengembalikan `access_token`
  (lihat test `assertNoTokenLeak` di `TikTokStoreApiTest`).
- Balas webhook **cepat** + proses async (job) supaya tak kena timeout/retry Shopee.

---

## 6. Rencana Test

- `ShopeeAuthTest`:
  - callback menukar `code`+`shop_id` → toko tersimpan (Http::fake token response).
  - callback tanpa `code` → 400/redirect error.
  - `state` invalid → 422.
- `ShopeeWebhookTest`:
  - signature valid → job ter-dispatch (`Queue::fake` / `Bus::fake`).
  - signature invalid → 401, job tidak dispatch.
  - delivery duplikat → 200 tanpa dispatch kedua.
- Jalankan: `php artisan test --filter=Shopee`
  ⚠️ **JANGAN** `migrate:fresh --env=testing` (akan menghapus DB dev `cilupbah`).

---

## 7. Operasional Pasca-Phase 1

- **Refresh token terjadwal**: token Shopee hanya 4 jam → tambah command `ShopeeRefreshTokens`
  (mirror `app/Console/Commands/LazadaRefreshTokens.php`) + scheduler, di fase berikutnya.
- **Config di Console Shopee**: daftarkan `…/api/v1/shopee/callback` sebagai redirect &
  `…/api/v1/shopee/webhook` sebagai Push URL (Live & Sandbox terpisah).

---

## 8. Roadmap Fase Lanjut (di luar scope Phase 1)

1. `ShopeeAdapter` + `AdapterFactory` (push/update/delete produk, sync price & stock).
2. `ShopeeOrderService` + `ShopeeToInternalOrderMapper` (pull & map order).
3. `ShopeeProductService` + mapper produk + kategori/atribut.
4. `ShopeeRefreshTokens` command + scheduler.
5. Store management UI (frontend `cilupbah-fe`).

---

## 9. Urutan Eksekusi (Phase 1)

1. Config + `.env.example` (`shopee` block).
2. `ShopeeSignature` + test unit kecil untuk sign.
3. `ShopeeClient` (auth URL + token get/refresh).
4. `ShopeeAuthService` + `ShopeeAuthController` + route `auth`/`callback`.
5. `ShopeeWebhookController` + `ProcessShopeeWebhook` (routing+log) + route `webhook`.
6. `AdapterFactory` case `shopee` (stub).
7. `ShopeeAuthTest` + `ShopeeWebhookTest` → `php artisan test --filter=Shopee`.
8. `graphify update .` untuk segarkan knowledge graph.
</content>
</invoke>
