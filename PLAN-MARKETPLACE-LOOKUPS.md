# Plan — 3 Endpoint Lookup Marketplace (Shopee/Tokopedia/Blibli)

> **Disusun:** 2026-06-11 · **PIC:** Darriel · **Scope:** id 263, 277, 44 (domain Product Listing).
> **Standar:** agents.md (Controller tipis → Service → Client/Repository; Resource; FormRequest).

## 0. Endpoint yang dikerjakan
| ID | Endpoint Jubelio | Fungsi | API marketplace (indikatif) |
|---|---|---|---|
| 263 | `GET /shopee/logistics` | Opsi logistik Shopee | `/api/v2/logistics/get_channel_list` |
| 277 | `GET /tokopedia/showcases` | Etalase Tokopedia | `GET /inventory/{fs_id}/fs/{fs_id}/showcase` |
| 44 | `GET /blibli/pickupPoints` | Titik pickup Blibli | `GET /pickup-points` (Blibli Seller API) |

Ketiganya **read-only lookup** yang dipakai saat menyiapkan listing/pengiriman per toko marketplace.

---

## 1. Hasil Verifikasi Kode (kondisi nyata)

| Aspek | Status | Catatan |
|---|---|---|
| Pola adapter multi-channel | ✅ ada | `AdapterFactory` + `MarketplaceAdapterInterface` |
| Adapter **TikTok** | ✅ ada | satu-satunya yang jalan |
| Adapter **Shopee/Tokopedia/Blibli** | 🔴 belum | masih komentar di `AdapterFactory` |
| Client API (base URL, signing) | 🔴 belum | hanya `TikTokClient` |
| Channel record | 🟡 sebagian | seed: shopee, tiktok, lazada, blibli — **Tokopedia BELUM di-seed** |
| Kredensial (`config/services.php`) | 🔴 belum | hanya `tiktok` |
| Toko terhubung (`ChannelShop.access_token`) | 🔴 belum | perlu OAuth per marketplace |

**Kesimpulan:** ini **bukan** pekerjaan alias seperti batch sebelumnya. Ketiganya **greenfield integrasi marketplace** — perlu client baru + kredensial + toko terhubung. Termasuk bagian dari **Epik E9 (Omnichannel)**.

---

## 2. ⚠️ DEPENDENSI / BLOKER (wajib dibaca)

Endpoint ini **memanggil API marketplace pakai kredensial toko**. Tanpa hal berikut, data nyata **tidak bisa** didapat:

1. **Kredensial partner API** (app key/secret/partner_id) untuk Shopee, Tokopedia, Blibli — harus didaftarkan di masing-masing developer portal. **Belum tersedia.**
2. **Toko terhubung** (OAuth → `access_token` tersimpan di `ChannelShop`). Flow OAuth per marketplace **belum dibangun**.
3. Tanpa (1)+(2): endpoint hanya bisa mengembalikan **422 "toko belum terhubung / kredensial belum diset"** (graceful, bukan 500).

> **Keputusan yang diperlukan dari Anda:** apakah
> **(A)** bangun integrasi nyata penuh (perlu kredensial + OAuth — besar), atau
> **(B)** bangun **kerangka + endpoint** sekarang (struktur lengkap sesuai agents.md, panggil client; kalau kredensial/toko belum ada → 422 jelas), kredensial diisi belakangan.
>
> **Rekomendasi: (B)** — endpoint & arsitektur siap, tinggal isi kredensial saat tersedia. Status tracker bisa jadi `done` (endpoint berfungsi & ter-uji), data riil menyusul setelah toko terhubung.

---

## 3. Arsitektur (sesuai agents.md)

```
Controller (tipis, per path Jubelio)
   └─ ShopeeLookupController@logistics / TokopediaLookupController@showcases / BlibliLookupController@pickupPoints
       └─ Service: ShopeeService@getLogistics($shop) / TokopediaService@getShowcases($shop) / BlibliService@getPickupPoints($shop)
           ├─ ChannelShopRepository@findActiveByShopId($shopId)   (DB lookup toko + access_token)
           └─ {Shopee|Tokopedia|Blibli}Client@request(...)        (panggil API marketplace, signing)
   └─ Output via Resource (LogisticsResource / ShowcaseResource / PickupPointResource)
```

- **Controller**: terima `shop_id` (query), validasi dasar, panggil service, balas Resource. Tidak ada logika.
- **Service**: resolve toko (repository), panggil client, transform hasil. Tangani error API.
- **Client**: base URL + auth/signing + `request()` (pola `TikTokClient`).
- **Repository**: `ChannelShopRepository` untuk ambil toko aktif + kredensial.
- **Resource**: bentuk response konsisten.
- **Guard**: `shop_id` wajib; toko tak ada/nonaktif → 422; kredensial kosong → 422; error API → 502/422 (bukan 500).

---

## 4. Task Per Fase

### Fase 0 — Fondasi bersama (1 hari)
- [ ] Seed channel **Tokopedia** (`code=tokopedia`) di `ChannelDatabaseSeeder`.
- [ ] Tambah blok kredensial di `config/services.php`: `shopee`, `tokopedia`, `blibli` (app_key/secret/partner_id/base_url) — nilai dari `.env` (boleh kosong dulu).
- [ ] `ChannelShopRepository@findActiveByShopId(string $shopId): ?ChannelShop` (Eloquent — lookup tunggal).
- [ ] Base `MarketplaceClient` abstrak/util (opsional) + 3 client skeleton: `ShopeeClient`, `TokopediaClient`, `BlibliClient` (base_url, signing stub, `request()`), masing-masing baca kredensial dari config & `access_token` dari shop.

### Fase 1 — Shopee logistics (id 263) (½ hari)
- [ ] `ShopeeClient@getLogistics(ChannelShop $shop): array` → `/api/v2/logistics/get_channel_list` (signing HMAC partner).
- [ ] `ShopeeService@getLogistics(string $shopId)` → repo + client + map.
- [ ] `ShopeeLookupController@logistics(Request)` → Resource.
- [ ] Route `GET shopee/logistics`. Resource `LogisticsResource`.

### Fase 2 — Tokopedia showcases (id 277) (½ hari)
- [ ] `TokopediaClient@getShowcases(ChannelShop $shop): array`.
- [ ] `TokopediaService@getShowcases($shopId)` + `TokopediaLookupController@showcases`.
- [ ] Route `GET tokopedia/showcases`. Resource `ShowcaseResource`.

### Fase 3 — Blibli pickupPoints (id 44) (½ hari)
- [ ] `BlibliClient@getPickupPoints(ChannelShop $shop): array`.
- [ ] `BlibliService@getPickupPoints($shopId)` + `BlibliLookupController@pickupPoints`.
- [ ] Route `GET blibli/pickupPoints`. Resource `PickupPointResource`.

**Total: ~2.5 hari** (Fase 0 paling berat karena fondasi 3 client).

---

## 5. Kontrak Endpoint (rencana)

| Method | Path | Query wajib | Sukses | Gagal |
|---|---|---|---|---|
| GET | `/shopee/logistics` | `shop_id` | 200 daftar channel logistik | 422 (toko/kredensial), 502 (API error) |
| GET | `/tokopedia/showcases` | `shop_id` | 200 daftar etalase | sda |
| GET | `/blibli/pickupPoints` | `shop_id` | 200 daftar titik pickup | sda |

> Jubelio juga punya path `system setting` Lazada (`/lazada/get-shipment-providers/{storeId}`) — pola sama; bisa menyusul setelah ketiga ini.

---

## 6. Definition of Done
1. Endpoint terdaftar & **tidak pernah 500** (toko/kredensial belum ada → 422 jelas; API error → 502).
2. Lapisan benar: Controller tipis → Service → Client + Repository; Resource; FormRequest/validasi `shop_id`.
3. Bila kredensial+toko diuji nyata → kembalikan data marketplace.
4. Unit/feature test: happy path (mock client) + error path (toko tak ada).
5. Tracker 263/277/44 → done.

## 7. Risiko
- **Kredensial & OAuth** = blocker data riil (lihat §2). Mitigasi: pendekatan (B) — kerangka jalan, kredensial menyusul.
- **Signing tiap marketplace beda** (Shopee HMAC partner, Tokopedia OAuth2 bearer, Blibli API key). Tiap client menangani sendiri.
- **Rate limit & sandbox**: gunakan base_url sandbox saat dev bila tersedia.
- Endpoint ini bagian **Epik E9 Omnichannel** — idealnya OAuth per marketplace dikerjakan paralel agar data riil cepat aktif.
