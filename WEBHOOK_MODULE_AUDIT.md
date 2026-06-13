# Audit Modul `Modules/Webhook`

> Tanggal: 2026-06-12
> Lingkup: error 500, kesalahan business logic, business flow, validasi, use case yang belum lengkap, dan pelanggaran `agents.md`.
> Fokus: jalur **TikTok & Lazada** (event order/produk/harga/stok yang dipicu pull & sync channel).
> Catatan: dokumen ini **hanya analisis + planning**. Tidak ada perubahan kode.

---

## Ringkasan Eksekutif

Modul ini adalah sistem **webhook keluar** (outgoing): subscriber mendaftar URL → observer pada model domain memicu event → dispatcher → `DispatchWebhookEventJob` (fan-out) → `SendWebhookJob` (HMAC-SHA256, retry+backoff). Desainnya **matang dan defensif**:

- ✅ **Tidak ada error 500.** Observer dibungkus `try/catch` (domain tidak pernah gagal karena webhook), controller pakai guard UUID → 404, validasi via FormRequest → 422. Terbukti oleh test `domain_operation_never_500_even_if_webhook_layer_throws` & `invalid_event_returns_422_not_500`.
- ✅ **Konsisten dengan `agents.md`** di lapisan HTTP: `successPaginatedResponse` + Resource, pagination default 10, Spatie Query Builder untuk listing, `whereUuid`, `auth:sanctum`, secret disembunyikan (`$hidden`).
- ✅ **Field payload semua valid** — diverifikasi terhadap fillable model (SalesOrder, Product, Variant, Inventory, Invoice, Payment, PO, Return, Transfer). **Tidak ada null-payload bug.**
- ✅ **Aman terhadap stock-lock**: dispatcher pakai `->afterCommit()` sehingga job masuk antrean setelah commit (rollback → tidak terkirim).

Temuan di bawah adalah **peningkatan**, bukan bug kritis. Yang paling penting: **SSRF pada `target_url`** dan **kelengkapan payload channel** untuk konsumen TikTok/Lazada.

---

## 🟠 MEDIUM

### M-1. SSRF — `target_url` tanpa guard host privat/loopback — ✅ FIXED
`WebhookUrlGuard` (skema dari `config('webhook.allowed_schemes')` default `https`; tolak IP loopback/link-local/privat/reserved via `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`, resolve DNS host). Dipakai dua lapis: rule `SafeWebhookUrl` saat pendaftaran **dan** cek ulang di `SendWebhookJob` (anti DNS-rebinding; URL internal → delivery gagal permanen tanpa retry). Tes: `WebhookHardeningTest` (guard unit, register 422, send blocked).

Detail awal:
**Lokasi:** [`StoreWebhookSubscriptionRequest.php:21`](Modules/Webhook/app/Http/Requests/StoreWebhookSubscriptionRequest.php:21) (`'target_url' => "...|url|max:2048"`), dieksekusi di [`SendWebhookJob::handle`](Modules/Webhook/app/Jobs/SendWebhookJob.php:65) (`Http::...->post($subscription->target_url)`).

`url` Laravel hanya memvalidasi format. Pengguna terautentikasi bisa mendaftarkan webhook ke endpoint **internal**: `http://localhost:6379`, `http://169.254.169.254/latest/meta-data` (metadata cloud), `http://10.x/...`, dll. Server lalu **mem-POST** ke sana setiap kali event terpicu → **SSRF** (pemindaian/serangan jaringan internal, ekstraksi metadata kredensial cloud). Risiko ditekan oleh `auth:sanctum`, tetapi tetap nyata (insider / akun ter-kompromi), dan rentan **DNS rebinding** karena tidak ada pengecekan saat kirim.

**Usulan:**
- Batasi skema ke `https` (atau `http,https`) saja.
- Tolak host loopback/link-local/privat (`127.0.0.0/8`, `::1`, `169.254.0.0/16`, `10/8`, `172.16/12`, `192.168/16`) — resolve DNS lalu cek IP saat validasi **dan** ulang saat `SendWebhookJob` (anti rebinding).
- Opsional: allowlist domain bila integrasi hanya ke partner tertentu.

### M-2. Pelanggaran arsitektur `agents.md §1` — query DB di dalam Job, bukan Repository — ✅ FIXED
`WebhookDeliveryRepository` dibuat (`firstOrCreateForEvent`, `findWithSubscription`, `incrementAttempts`, `markSuccess`, `markFailed`, `resetForRedelivery`, `paginateForSubscription`). `DispatchWebhookEventJob` & `SendWebhookJob` kini memakai repository (tanpa query Eloquent langsung).

Detail awal:
Belum ada `WebhookDeliveryRepository`. Interaksi DB `webhook_deliveries` tersebar di Job:
- [`DispatchWebhookEventJob::handle`](Modules/Webhook/app/Jobs/DispatchWebhookEventJob.php:39): `WebhookDelivery::create([...])`.
- [`SendWebhookJob::handle`](Modules/Webhook/app/Jobs/SendWebhookJob.php:36): `WebhookDelivery::with('subscription')->find(...)`, `->increment('attempts')`, `->update([...])`.
- [`SendWebhookJob::failed`](Modules/Webhook/app/Jobs/SendWebhookJob.php:91): `WebhookDelivery::find(...)->update([...])`.

`agents.md §1` mewajibkan seluruh interaksi DB berada di Repository. (Subscription sudah benar via `WebhookSubscriptionRepository`; delivery belum.)

**Usulan:** buat `WebhookDeliveryRepository` (`create`, `findWithSubscription`, `markSuccess`, `markFailed`, `incrementAttempt`) dan panggil dari Job.

### M-3. Use case belum lengkap — observabilitas & replay delivery + dead-endpoint — ✅ FIXED
- `GET /v1/webhooks/{id}/deliveries` (Spatie QB, paginate 10, filter `status`/`event`) + `POST /v1/webhook-deliveries/{id}/redeliver` (reset → antre ulang) via `WebhookDeliveryService` + `WebhookDeliveryResource`.
- Auto-disable: kolom `consecutive_failures` (migrasi baru); `SendWebhookJob` reset saat sukses, `failed()` increment & nonaktifkan subscription saat `>= config('webhook.max_consecutive_failures', 15)`. Tes: list/redeliver/404/auto-disable.

Detail awal:
- **Tidak ada endpoint** untuk melihat `webhook_deliveries` atau **re-deliver** delivery yang gagal. Tabel kaya data (`status`, `status_code`, `attempts`, `last_error`, `delivered_at`) tapi tak terjangkau via API → admin/partner tak bisa audit atau kirim ulang.
- **Tidak ada auto-disable** subscription setelah sekian kegagalan beruntun. Endpoint subscriber yang mati akan terus dikirimi pada **setiap** event (boros antrean + retry 3x setiap kali).

**Usulan:** tambah `GET /v1/webhooks/{id}/deliveries` (Spatie QB, paginate 10) + `POST /v1/webhook-deliveries/{id}/redeliver`; pertimbangkan circuit-breaker (nonaktifkan/flag subscription setelah N gagal beruntun, atau backoff per-subscription).

---

## 🟡 LOW

### L-1. Kelengkapan payload `salesorder` untuk konsumen TikTok/Lazada — ✅ FIXED
Payload `salesorder` kini memuat `source`, `channel_status`, `tracking_number`, `shipping_provider`, dan `items` (hanya bila relasi sudah ter-load — tanpa query tambahan di dalam transaksi domain). Tes: `salesorder_payload_includes_channel_context`.

Detail awal:
**Lokasi:** [`SalesOrderWebhookObserver::payload`](Modules/Webhook/app/Observers/SalesOrderWebhookObserver.php:25).

Payload mengirim `status` (status **internal**), `channel_shop_id`, `salesorder_no`, `grand_total`, `action`. Untuk subscriber yang memproses order TikTok/Lazada, **kurang konteks channel**:
- Tidak ada `source` (tiktok/lazada) → subscriber tak tahu asal order.
- Tidak ada `channel_status` (status asli marketplace) → hanya status internal hasil mapping.
- Tidak ada **line items**.

**Usulan:** tambahkan `source`, `channel_status`, dan (opsional) ringkasan item ke payload. (Kolom `source`/`channel_status` tersedia di `sales_orders` — lihat audit Channel.)

### L-2. `salesorder.updated` hanya emit saat `status` internal berubah — ✅ FIXED
`SalesOrderWebhookObserver::updated` kini juga emit saat `tracking_number`/`shipping_provider` berubah (`action: shipment_updated`), selain `status_changed`.

Detail awal:
**Lokasi:** [`SalesOrderWebhookObserver::updated`](Modules/Webhook/app/Observers/SalesOrderWebhookObserver.php:16) (`if (! $order->wasChanged('status')) return;`).

Transisi yang **tidak** mengubah status internal (mis. penambahan `tracking_number`/`shipping_provider`, perubahan `channel_status` yang map ke status internal sama) **tidak** memicu webhook. Bila subscriber butuh update pengiriman/tracking, ini gap. (Mungkin disengaja — perlu konfirmasi kebutuhan.)

### L-3. `product` hanya emit `created`, tak ada perubahan status — ✅ FIXED
`ProductWebhookObserver::updated` ditambahkan (emit saat `status` berubah, `action: status_changed`); payload `created` kini juga membawa `action: created`.

Detail awal:
**Lokasi:** [`ProductWebhookObserver`](Modules/Webhook/app/Observers/ProductWebhookObserver.php:10) hanya `created`.

Perubahan status produk (`download → in_review → master`, atau deaktivasi dari webhook channel) tidak mengirim event `product`. Bila konsumen perlu tahu produk hasil download TikTok berpindah status, ini belum tercakup. (Mungkin disengaja.)

### L-4. Hot-path dispatcher bisa query DB di dalam transaksi domain (docblock over-claim) — ✅ FIXED
Cache dihangatkan ulang (`refreshCache()` = `Cache::put`) setiap subscription dibuat/diubah/dihapus (di luar transaksi domain), TTL `config('webhook.active_events_ttl', 300)`. Cache-miss di dalam lock kini jarang & hanya satu SELECT read-only; docblock dikoreksi agar jujur.

Detail awal:
**Lokasi:** [`WebhookDispatcherService::activeEvents`](Modules/Webhook/app/Services/WebhookDispatcherService.php:43) — `Cache::remember(... fn () => $repository->activeEventNames())`.

Docblock mengklaim "Hot-path hanya cache lookup (TANPA query DB di dalam transaksi/lock)". Namun saat **cache miss**, `activeEventNames()` menjalankan `SELECT` di dalam transaksi/lock domain pemicu; bila cache store = `database`, juga ada **write** cache di dalam transaksi. Bukan deadlock (read), tapi mengingkari klaim & menambah kueri di dalam lock.

**Usulan:** hangatkan cache di luar hot-path (mis. saat subscription berubah / scheduler), atau perbaiki klaim di komentar.

### L-5. `DispatchWebhookEventJob` tidak idempoten saat retry — ✅ FIXED
`event_id` dibangkitkan di dispatcher lalu dioper ke job (stabil lintas-retry). Job memakai `firstOrCreateForEvent` + unique index `(subscription_id, event_id)` (migrasi baru); `SendWebhookJob` hanya di-dispatch untuk delivery yang `wasRecentlyCreated`. Tes: `dispatch_job_idempotent_per_event_id`.

Detail awal:
**Lokasi:** [`DispatchWebhookEventJob::handle`](Modules/Webhook/app/Jobs/DispatchWebhookEventJob.php:36) — `$eventId = (string) Str::uuid7();` dibuat **di dalam** handle.

Bila job di-retry setelah membuat sebagian delivery, eksekusi ulang menghasilkan `event_id` **baru** dan membuat ulang delivery → subscriber bisa menerima kejadian yang sama dengan dua `event_id` berbeda (idempotency subscriber jebol). Tabel `webhook_deliveries` juga tak punya unique `(subscription_id, event_id)`.

**Usulan:** bangkitkan `event_id` di **dispatcher** lalu oper sebagai properti job (stabil lintas-retry); pertimbangkan unique `(subscription_id, event_id)` + `firstOrCreate`.

### L-6. Respons non-2xx (termasuk 3xx) dianggap gagal — ✅ FIXED
`SendWebhookJob` kini menerima 2xx **dan** 3xx (`$response->successful() || $response->redirect()`) sebagai terkirim. Tes: `send_treats_3xx_as_delivered`.

Detail awal:
**Lokasi:** [`SendWebhookJob::handle`](Modules/Webhook/app/Jobs/SendWebhookJob.php:67) — `if ($response->successful())` (hanya 2xx).

Subscriber yang membalas `301/302` akan ditandai **failed** + retry. Edge-case kecil; pertimbangkan menerima 3xx atau follow-redirect bila relevan.

---

## ✅ Yang sudah benar (PASS)

- **Anti-500**: observer `try/catch` ([`AbstractWebhookObserver`](Modules/Webhook/app/Observers/AbstractWebhookObserver.php:15)); controller guard UUID → 404 ([`WebhookController::resolve`](Modules/Webhook/app/Http/Controllers/WebhookController.php:76)); validasi `in:`/`url` → 422.
- **afterCommit** + queue terpisah → konsisten dengan rollback transaksi stok.
- **HMAC-SHA256** signature + header `X-Cilupbah-*`, secret di-`$hidden` & hanya ditampilkan sekali saat create.
- **Retry + backoff** `[10,60,300]`, `failed()` mencatat status akhir.
- **Validasi**: `event` dibatasi `WebhookEvent::subscriptionValues()` (9 event + `*`); update pakai `sometimes` (partial update aman).
- **agents.md HTTP-layer**: Resource, `successPaginatedResponse`, pagination 10, Spatie QB di Repository, `auth:sanctum`, `whereUuid`.
- **Field payload** semua cocok dengan kolom model (tidak ada atribut salah ketik).
- **Cakupan test** baik: semua observer + register/observe/no-500 teruji (`WebhookTest`, `WebhookObserverTest`).

---

## Planning / Rencana Perbaikan

### Fase 1 — Security (MEDIUM, ~0.5 hari)
1. **M-1 SSRF**: rule kustom `target_url` (skema https; tolak IP loopback/link-local/privat via resolve DNS) + cek ulang saat `SendWebhookJob`. Tambah test: URL internal → 422; rebinding → delivery dibatalkan.

### Fase 2 — Arsitektur & ketahanan (MEDIUM, ~0.5–1 hari)
2. **M-2**: ekstrak `WebhookDeliveryRepository`, pindahkan semua query delivery dari Job.
3. **L-5**: `event_id` dibangkitkan di dispatcher + unique `(subscription_id, event_id)`.
4. **L-4**: hangatkan cache event aktif di luar hot-path / koreksi klaim docblock.

### Fase 3 — Kelengkapan use case (MEDIUM/LOW, ~1 hari)
5. **M-3**: endpoint list deliveries + redeliver; circuit-breaker dead-endpoint (auto-disable/flag).
6. **L-1**: perkaya payload `salesorder` (`source`, `channel_status`, items) untuk konsumen TikTok/Lazada.
7. **L-2/L-3**: konfirmasi kebutuhan emit pada update tracking/shipment & perubahan status produk; implementasi bila perlu.

### Fase 4 — Penyempurnaan (LOW)
8. **L-6**: kebijakan 3xx pada `SendWebhookJob`.

### Catatan pengujian
- Suite Webhook saat ini hijau (`WebhookTest`, `WebhookObserverTest`). Tambah test untuk M-1 (SSRF), M-3 (deliveries/redeliver), dan L-5 (idempotency retry).
- Jalankan via `rtk` (mis. `rtk test php artisan test --filter=Webhook`).

---

## Lampiran — Inventaris cepat

| Komponen | Status |
|---|---|
| `WebhookController` (CRUD + systemsetting alias) | OK (guard UUID, Resource, paginated) |
| `StoreWebhookSubscriptionRequest` | ⚠️ M-1 (SSRF `target_url`) |
| `WebhookDispatcherService` | ⚠️ L-4 (cache-in-transaction) |
| `DispatchWebhookEventJob` | ⚠️ M-2, L-5 |
| `SendWebhookJob` | ⚠️ M-2, L-6 (HMAC/retry sudah OK) |
| Observers (SalesOrder/Product/Variant/Inventory — TikTok/Lazada) | OK; ⚠️ L-1/L-2/L-3 kelengkapan |
| Observers Fase-2 (Invoice/Payment/PO/Return/Transfer) | OK (field valid) |
| `webhook_deliveries` (tabel) | ⚠️ M-3 (tak ada API list/replay), L-5 (tak ada unique event) |
| Validasi & respons | OK (422/404, tanpa 500) |
