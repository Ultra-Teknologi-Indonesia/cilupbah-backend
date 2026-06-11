# Plan — Webhooks Outbound (9 event) · Fokus TikTok Shop

> **Disusun:** 2026-06-11 · **PIC:** Darriel · **Scope:** id 279–287 (domain Webhooks) + registrasi (id 267 `/systemsetting/webhook`).
> **Standar:** agents.md (Controller tipis → Service → Repository; Resource; FormRequest).
> **Prinsip wajib:** **TIDAK ada error 500**, **tidak bentrok** dengan modul lain, **terintegrasi non-invasif** dengan pekerjaan Rasyid (Sales/Purchase/Inventory).

---

## 1. Interpretasi & Ruang Lingkup

9 endpoint `/webhooks/*` Jubelio = **notifikasi event OUTBOUND** (Cilupbah → URL subscriber yang terdaftar), pola "Cilupbah bertindak sebagai Jubelio". Saat terjadi perubahan domain (invoice baru, stok berubah, dst), Cilupbah **mengirim HTTP POST bertanda-tangan** ke subscriber.

**Fokus TikTok Shop:** TikTok adalah satu-satunya channel yang hidup. Maka **Fase 1** mengaktifkan event yang **benar-benar terpicu oleh alur TikTok yang sudah jalan** (produk, harga, stok, sales order dari TikTok) sehingga bisa diuji end-to-end **sekarang**. Event akuntansi/ops (invoice, payment, PO, retur, transfer) di-wire ke model Rasyid pada **Fase 2** (dipicu via aksi API).

> Penting: sistem webhook ini **channel-agnostic** (kirim ke URL subscriber apa pun). Dimensi "TikTok" hanya menentukan **event mana yang bisa kita uji nyata lebih dulu**.

---

## 2. Arsitektur (non-invasif, queued, aman)

```
[Model domain milik Rasyid/Darriel]         (TIDAK diubah)
  SalesInvoice / SalesPayment / SalesOrder / SalesReturn
  PurchaseOrder / Inventory / InventoryTransfer / Product / ProductVariant
        │  (Eloquent model events: created/updated)
        ▼
[Observer di MODUL WEBHOOK]   <-- pola sama spt ProductObserver yg sudah ada
  WebhookEventObserver::created/updated()
        │  panggil (dibungkus try/catch, tidak pernah melempar ke transaksi pemicu)
        ▼
[WebhookDispatcherService::dispatch(event, payload-ringan)]
        │  SendWebhookJob::dispatch(...)->afterCommit()   ← WAJIB (lihat §6b)
        │  (job baru masuk antrean SETELAH commit & lock stok dilepas)
        ▼
[SendWebhookJob]  (queue: "webhooks", retry + backoff, build payload final DI SINI)
        │  POST payload + header X-Cilupbah-Signature (HMAC-SHA256)
        ▼
[URL subscriber]  → response dicatat di webhook_deliveries
```

**Kenapa Observer (bukan ubah service Rasyid):** Observer didaftarkan dari `Modules/Webhook` (`Model::observe(...)`), **tanpa menyentuh satu baris pun kode Rasyid**. Ini pola yang SUDAH dipakai `ProductObserver`. → integrasi rapi, nol konflik.

**Kenapa queued + try/catch:** pengiriman webhook **asinkron**. Kalau subscriber mati/lambat, operasi pemicu (mis. buat invoice) **tetap sukses** → **tidak ada 500**, flow domain tidak terganggu.

---

## 2c. 🔗 Keselarasan dengan TikTok Shop Omnichannel (terverifikasi di kode)

Ada **3 jalur berbeda** yang TIDAK boleh tertukar. Webhook (plan ini) adalah jalur ke-3, **terpisah** dari 2 jalur TikTok yang sudah ada:

| Jalur | Arah | Mekanisme (sudah ada / baru) | Konsumen |
|---|---|---|---|
| **A. TikTok Inbound** | TikTok → Cilupbah | `POST v1/tiktok/webhook` → `ProcessTikTokWebhook` (type 1=order→`SalesOrder`, 2/3=product) | internal Cilupbah |
| **B. Channel Sync Outbound** | Cilupbah → TikTok | `SyncProductToChannelJob` / `SyncStockToChannelsJob` (dipicu `ProductObserver` & `ChannelProductService`) | marketplace TikTok |
| **C. Webhook Notifikasi (PLAN INI)** | Cilupbah → **subscriber/merchant** | `SendWebhookJob` (baru) | sistem eksternal/merchant |

**Hasil verifikasi yang membuat plan ini selaras:**
1. **Order TikTok → `SalesOrder`** (terbukti: `TikTokOrderService` memakai `Modules\Sales\Services\SalesOrderService`, `pullOrderById`). → observer `salesorder` saya **otomatis menangkap order yang berasal dari TikTok**. ✅
2. **Webhook ≠ Channel Sync.** Jalur C (notifikasi ke merchant) **tidak menggantikan & tidak menduplikasi** jalur B (push ke marketplace). Mereka beda konsumen. Subscriber webhook **tidak boleh** dipakai untuk "sync ke TikTok" — itu tetap tugas jalur B.
3. **Observer webhook bersifat ADITIF**, hidup berdampingan dengan `ProductObserver` (jalur B) pada model yang sama. Laravel menjalankan beberapa observer; keduanya pakai `->afterCommit()` → independen, tidak saling blok.
4. **Tidak ada loop.** Jalur A (TikTok→Cilupbah) dan C (Cilupbah→subscriber) arahnya beda & tujuan beda; webhook C tidak menembak balik ke TikTok.
5. **`stock` event** belum punya observer (jalur B men-sync stok via panggilan service eksplisit, bukan observer Inventory). Maka observer `stock` adalah **komponen baru** milik modul Webhook — independen dari jalur B.
6. **Idempotency selaras:** jalur A pakai kunci Redis; jalur C pakai `event_id` unik (dedup di sisi subscriber). Tidak bertabrakan.

> Kesimpulan keselarasan: order/produk/stok yang **bersumber dari TikTok** akan memicu webhook yang benar (karena semuanya bermuara ke model internal `SalesOrder`/`Product`/`Inventory`), **tanpa mengganggu** sinkronisasi TikTok yang sudah berjalan.

---

## 3. Pemetaan Event → Model → Owner (integrasi Rasyid)

| Event (id)              | Path Jubelio              | Model sumber        | Owner      | Trigger                         |
| ----------------------- | ------------------------- | ------------------- | ---------- | ------------------------------- |
| **product** (282)       | `/webhooks/product`       | `Product`           | 🔵 Darriel | created                         |
| **price** (281)         | `/webhooks/price`         | `ProductVariant`    | 🔵 Darriel | updated (`sell_price`)          |
| **stock** (286)         | `/webhooks/stock`         | `Inventory`         | 🟢 Rasyid  | updated (`on_hand`/`available`) |
| **stocktransfer** (287) | `/webhooks/stocktransfer` | `InventoryTransfer` | 🟢 Rasyid  | created                         |
| **salesorder** (284)    | `/webhooks/salesorder`    | `SalesOrder`        | 🟢 Rasyid  | created / updated status        |
| **salesreturn** (285)   | `/webhooks/salesreturn`   | `SalesReturn`       | 🟢 Rasyid  | created                         |
| **invoice** (279)       | `/webhooks/invoice`       | `SalesInvoice`      | 🟢 Rasyid  | created                         |
| **payment** (280)       | `/webhooks/payment`       | `SalesPayment`      | 🟢 Rasyid  | created                         |
| **purchaseorder** (283) | `/webhooks/purchaseorder` | `PurchaseOrder`     | 🟢 Rasyid  | created                         |

> Semua model di atas **sudah ada** (Rasyid 209/210 done). Observer hanya **membaca** model + memanggil dispatcher; payload dibentuk via Resource ringkas (id + field kunci), **bukan** menggandakan logika bisnis Rasyid.

---

## 4. Skema Database (modul Webhook)

**`webhook_subscriptions`**
| kolom | tipe | ket |
|---|---|---|
| id | uuid PK | |
| event | varchar | salah satu dari 9 event (atau `*` semua) |
| target_url | varchar | URL subscriber |
| secret | varchar | untuk HMAC signature |
| is_active | boolean | |
| timestamps | | |

**`webhook_deliveries`** (audit & retry)
| kolom | tipe | ket |
|---|---|---|
| id | uuid PK | |
| subscription_id | uuid | |
| event | varchar | |
| payload | jsonb | |
| status_code | int null | |
| attempts | int | |
| last_error | text null | |
| delivered_at | timestamp null | |
| timestamps | | |

---

## 5. Komponen (sesuai agents.md)

```
Modules/Webhook/
  database/migrations/xxxx_create_webhook_subscriptions_table.php
  database/migrations/xxxx_create_webhook_deliveries_table.php
  app/Models/WebhookSubscription.php
  app/Models/WebhookDelivery.php
  app/Repositories/WebhookSubscriptionRepository.php   (Spatie utk listing)
  app/Services/WebhookDispatcherService.php            (dispatch event -> queue)
  app/Services/WebhookSubscriptionService.php          (CRUD subscription)
  app/Jobs/SendWebhookJob.php                          (kirim + sign + retry + log)
  app/Observers/WebhookEventObserver.php               (atau observer per-model tipis)
  app/Http/Controllers/WebhookController.php           (rewrite dari stub blade -> API)
  app/Http/Requests/StoreWebhookSubscriptionRequest.php
  app/Http/Resources/WebhookSubscriptionResource.php
  app/Providers/EventServiceProvider.php               (daftarkan Model::observe(...))
  app/Support/WebhookEvent.php                          (const 9 nama event)
  config/config.php                                     (queue name, timeout, max_retry)
```

**Registrasi observer** (di `Webhook/EventServiceProvider`, contoh):

```php
\Modules\Sales\Models\SalesInvoice::observe(InvoiceWebhookObserver::class);
\Modules\Inventory\Models\Inventory::observe(StockWebhookObserver::class);
// ...dst — TANPA mengubah modul Sales/Inventory.
```

---

## 6. Garansi "Tidak 500" & Flow Benar

1. **Observer dibungkus try/catch** → bila pembentukan payload/dispatch gagal, error **di-log**, tidak dilempar ke request pemicu. Operasi domain (Rasyid) tetap sukses.
2. **Dispatch = enqueue saja** (cepat, non-blocking). Pengiriman HTTP terjadi di `SendWebhookJob` (worker), **di luar** request siklus.
3. **Idempotent**: payload menyertakan `event_id` unik; subscriber bisa dedup.
4. **Retry + backoff** di job (mis. 3x, 10s/60s/300s); kegagalan akhir → `webhook_deliveries.last_error`, **tidak** mengganggu apa pun.
5. **Tanpa subscriber** → dispatcher tidak melakukan apa-apa (no-op), nol overhead.
6. **Registrasi endpoint** validasi via FormRequest → input salah = **422**, bukan 500.
7. **Observer hanya aktif untuk perubahan relevan** (mis. stock hanya saat `on_hand` berubah) agar tidak spam & tidak memicu efek samping.

---

## 6b. 🔒 Keamanan Stock Locking & Transaksi (WAJIB — jawaban atas risiko integrasi)

**Fakta kode Rasyid:** stok di-update dengan **pessimistic lock** — `InventoryRepository::findOrCreateForUpdate()` memanggil `lockForUpdate()` di dalam `DB::transaction`, lalu ubah `on_hand`/`available`/`reserved`. Lock serupa dipakai di Sales/Purchase/Outbound (`SalesInvoice`, `SalesOrder`, `PurchaseOrder`, dll). Queue driver = **`database`** dan `after_commit` global = **false**.

**Konsekuensi:** Eloquent observer (`created`/`updated`) menyala **di dalam transaksi yang sedang memegang lock**. Bila tidak ditangani, webhook akan: (a) memperpanjang durasi lock → kontensi/deadlock saat konkuren, dan (b) salah-notifikasi bila transaksi **rollback**.

### Aturan WAJIB (mengikuti pola `->afterCommit()` yang SUDAH ada di repo)
1. **Dispatch HANYA `->afterCommit()`.** `SendWebhookJob::dispatch(...)->afterCommit()` — job baru masuk antrean **setelah commit & lock dilepas**. (Pola identik dipakai `ProductChannelDraftService`, `RaiseProductService`, `ChannelDownloadService`.)
   - Efek: tidak menambah waktu tahan lock; **rollback → webhook batal** (notifikasi konsisten dgn data ter-commit).
2. **Observer melakukan kerja nol.** Di dalam transaksi, observer **hanya** menangkap `id` + nama event + snapshot field minimal (dari atribut model yang sudah dimuat) lalu memanggil dispatcher. **DILARANG query DB / build payload berat di observer** (itu menambah beban di dalam lock).
3. **Payload dibangun di dalam job** (`SendWebhookJob`), **setelah commit** — saat itu data sudah final & lock lepas, query aman.
4. **Anti-spam pada stok:** operasi stok sering mengubah banyak baris `inventories` dalam satu transaksi. Maka:
   - Fire `stock` hanya bila `isDirty('on_hand')` / `isDirty('available')`, dan
   - **Dedup per transaksi**: kumpulkan item yang berubah, kirim **satu** webhook `stock` berisi daftar item setelah commit (bukan satu webhook per baris). Implementasi: buffer di memori per-request + flush di `afterCommit`.
5. **Tidak menyentuh write-set transaksi stok.** Karena dispatch `afterCommit` & queue `database`, insert ke tabel `jobs` terjadi **di luar** transaksi stok → write-set Rasyid tidak berubah, risiko deadlock nol.
6. **Jangan observe operasi internal yang tak relevan** (mis. perubahan `reserved` saat locking sementara) bila tidak diinginkan sebagai event publik — batasi ke perubahan `on_hand`/`available` yang bermakna bagi subscriber.

### Verifikasi yang harus lulus (uji integrasi locking)
- **Uji rollback:** picu operasi stok yang gagal (stok kurang) → transaksi rollback → **tidak ada** webhook terkirim (assert tak ada job).
- **Uji konkuren:** 2 request decrement stok item sama bersamaan → keduanya benar (locking Rasyid tetap jalan), webhook terkirim **setelah** masing-masing commit, tidak deadlock.
- **Uji durasi lock:** observer tidak menambah query di dalam transaksi (assert via query log / waktu tahan lock tidak naik signifikan).

> Ringkasnya: webhook **tidak pernah** ikut di jalur kritis locking. Ia hanya "menumpang" sinyal model, lalu **menunda seluruh kerja ke setelah commit**. Flow stok & locking Rasyid **tidak berubah sama sekali**.

---

## 7. Pencegahan Bentrok (penting)

- **Nama route unik** — hindari kasus `route:cache` gagal (pelajaran sebelumnya). Endpoint registrasi pakai nama eksplisit unik, mis. `webhook.subscriptions.*`.
- **Queue terpisah** `webhooks` — jangan campur dengan `channel_sync`/`downloads`.
- **Batas modul** — semua logika webhook di `Modules/Webhook`; modul lain tidak di-`use` balik (hindari circular). Observer hanya `use` model (read-only).
- **Tidak menimpa** webhook INBOUND TikTok yang sudah ada (`POST v1/tiktok/webhook`) — itu beda fungsi (terima dari TikTok), tetap dipertahankan.
- **Migrasi UUID** — model webhook pakai `HasUuid7`; FK subscription_id uuid. Konsisten dengan skema terkini.

---

## 8. Fokus TikTok — Phasing

### Fase 0 — Infrastruktur (1–1.5 hari)

Migrasi + model + `WebhookDispatcherService` + `SendWebhookJob` (HMAC, retry, log) + CRUD subscription (`WebhookController` rewrite + `POST /systemsetting/webhook`) + `WebhookSubscriptionResource`/Request. **Belum ada observer** → diuji manual via dispatcher + endpoint test.

### Fase 1 — Event TikTok-driven (testable sekarang) (1 hari)

Observer untuk: **product** (282), **price** (281), **stock** (286), **salesorder** (284).
Alasan: alur TikTok hidup → produk di-push, stok/harga sync, order TikTok masuk jadi SalesOrder. Bisa diuji end-to-end nyata.

### Fase 2 — Event akuntansi/ops (1 hari)

Observer untuk: **invoice** (279), **payment** (280), **purchaseorder** (283), **salesreturn** (285), **stocktransfer** (287). Dipicu via aksi API modul Rasyid.

**Total ~3–3.5 hari.** Setiap event → `done` setelah observer + delivery teruji (payload terkirim, tercatat di `webhook_deliveries`).

---

## 9. Definition of Done (per event)

1. Observer terpasang (non-invasif) & memicu dispatcher pada lifecycle yang benar.
2. `SendWebhookJob` mengirim POST bertanda-tangan; sukses/gagal tercatat di `webhook_deliveries`.
3. **Operasi domain pemicu tidak pernah 500** walau subscriber mati (diuji: matikan URL → domain tetap sukses, delivery `failed` + retry).
4. Registrasi subscription via API (validasi 422 untuk input salah).
5. Min. 1 feature test: domain action → webhook ter-enqueue (assert `Queue::pushed`).
6. **Uji stock-locking (§6b):** rollback transaksi stok → **tidak ada** webhook; konkuren decrement → tidak deadlock; observer tidak menambah query di dalam lock.
7. Dispatch memakai `->afterCommit()` (di-review eksplisit).
6. Tracker event terkait → `done`.

---

## 10. Mapping Tracker

- 279–287 (9 event) → `done` bertahap per fase.
- 267 `/systemsetting/webhook` (registrasi) → `done` di Fase 0.
- Epik **E8 Webhooks outbound** (id 295) → `done` saat 9 event selesai.

---

## 11. Risiko & Mitigasi

| Risiko                                  | Mitigasi                                                         |
| --------------------------------------- | ---------------------------------------------------------------- |
| Webhook gagal → ganggu transaksi Rasyid | Async queued + try/catch; dispatch tak pernah melempar           |
| Spam event (stok sering berubah)        | Observer filter perubahan signifikan + dedup `event_id`          |
| Subscriber lambat                       | Timeout pendek di job + retry backoff                            |
| Bentrok nama route / queue              | Nama unik + queue `webhooks` khusus (lihat §7)                   |
| Payload bocorkan data sensitif          | Resource ringkas (id + field kunci), HMAC signature, tanpa token |
| Worker queue belum jalan di staging     | Pastikan `queue:work` aktif; fallback `sync` di lokal utk test   |

```

```
