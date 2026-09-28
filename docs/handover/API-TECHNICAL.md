# Dokumentasi API Teknis

## 1. Stack dan sumber kontrak

- Backend: Laravel 12, PHP 8.2+, PostgreSQL.
- Authentication: Laravel Sanctum sesuai konfigurasi environment.
- Queue: Laravel Horizon + Redis dengan koneksi/queue terpisah menurut beban.
- API documentation: L5-Swagger/OpenAPI.
- Source annotations: `cilupbah-be/app` dan `cilupbah-be/Modules`.
- Generated contract: `cilupbah-be/storage/api-docs/api-docs.json`.
- Swagger UI: `/api/documentation`.

File JSON adalah artefak generated, bukan tempat mengedit kontrak secara manual.
Perubahan endpoint dilakukan pada route/request/resource/controller annotation,
lalu spesifikasi dibuat ulang.

## 1.1 Environment production

- Web client: `https://app.ultra-fit.id/`
- API base URL: `https://be-superapp.ultra-fit.id/`
- Swagger UI: `https://be-superapp.ultra-fit.id/api/documentation`

Host lama tidak boleh dipakai sebagai callback baru tanpa persetujuan cutover.
URL webhook/OAuth marketplace harus dicatat pada
[Matriks Webhook & Callback](WEBHOOK-CALLBACK-MATRIX.md) dan diuji per channel.

## 2. Generate dan verifikasi OpenAPI

Jalankan dari direktori backend pada environment yang benar:

```bash
php artisan l5-swagger:generate
php artisan route:list --json > /tmp/routes.json
```

Verifikasi minimum:

```bash
jq '.openapi, .info, (.paths | length)' storage/api-docs/api-docs.json
jq -e '.paths | type == "object"' storage/api-docs/api-docs.json
```

Spesifikasi saat audit workspace ini berisi 101 path terdokumentasi. Route
Laravel dapat lebih banyak karena route internal, detail, health check, atau
endpoint yang belum dianotasi. Sebelum BAST, cocokkan daftar endpoint yang memang
menjadi kontrak eksternal dengan OpenAPI; jangan mengklaim semua route otomatis
terdokumentasi hanya karena Swagger UI tersedia.

## 3. Konvensi request/response

- Endpoint listing memakai pagination, filter, sort, dan search di server.
- Backend mengembalikan envelope `ApiResponse<T>` (`data` dan `meta`) sesuai kontrak project.
- Search bebas menggunakan parameter `search` sesuai allowed search endpoint.
- Filter dan sort hanya memakai field yang diizinkan endpoint.
- Klien harus meneruskan query saat berpindah halaman.
- Detail resource memakai identifier route; jangan memakai list endpoint untuk mengambil semua data lalu memfilter di browser.
- Job berat, export, label, dan sinkronisasi channel diproses async; respons HTTP dapat berupa accepted/progress resource, bukan hasil PDF langsung.

Nilai `per_page`, field sort/filter, dan permission harus mengikuti schema endpoint
pada OpenAPI atau implementasi repository. Jangan menebak parameter yang tidak
tercantum.

## 4. Domain API

Kelompok tag yang tersedia pada kontrak generated meliputi:

- Auth, Users, Roles, Permissions
- Products, Channel Products, Media
- Locations, Location Zones, Location Bins, Channel Warehouses
- Inbounds, Assignment, QR Scan, Putaway
- Inventory, Inventory Transactions, Reserved Stock, Stock Adjustment
- Orders, Outbounds, Reports, Notifications, Webhooks
- Purchase Orders, Suppliers, Sales Returns, Warranty

Integrasi marketplace dan operasi channel memiliki endpoint autentikasi, toko,
produk, order, stock, logistics, webhook, retry, dan monitoring. Rincian path,
request body, permission, dan response schema harus dibaca dari Swagger UI atau
JSON generated untuk versi image yang sedang dijalankan.

## 5. Webhook dan idempotensi

1. Endpoint channel menerima event dari marketplace dan harus merespons cepat.
2. Payload disimpan di inbox/event log sebelum pekerjaan berat dijalankan.
3. Pemrosesan lanjutan dilakukan oleh job queue.
4. Event duplikat harus aman diproses ulang berdasarkan event/order key.
5. Status `RECEIVED`, `PROCESSED`, `FAILED`, atau `SKIPPED` harus dapat diaudit.
6. Error channel, retry count, next attempt, dan timestamp perlu disertakan saat eskalasi.

Jangan menonaktifkan verifikasi signature/token hanya untuk mengatasi error.
Kredensial dan secret webhook disimpan di secret manager/environment, bukan di
source code.

## 6. Integrasi channel

Marketplace dapat memiliki proses async, rate limit, batas batch, dan waktu terbit
AWB yang berbeda. Adapter/service channel bertanggung jawab terhadap signing,
token refresh, batching yang diizinkan, polling dengan backoff, deduplikasi, dan
error mapping. API pihak ketiga tidak boleh dipanggil berulang tanpa idempotency
guard atau status pekerjaan yang jelas.

## 7. Error dan observability

- 401/403: token atau permission tidak valid.
- 404: resource tidak ditemukan pada scope toko/user.
- 409: konflik status/idempotency/versi data.
- 422: validasi request gagal.
- 429: rate limit; hormati `Retry-After`/backoff.
- 5xx: kegagalan internal atau upstream; cek request id dan log job.

Setiap laporan insiden minimal mencantumkan endpoint, method, user/shop, request
id, waktu WIB/UTC, status code, queue, dan pesan error upstream. Jangan menyertakan
access token atau payload PII penuh.

## 8. Checklist API sebelum handover

- [ ] `api-docs.json` dibuat dari commit/image yang diserahkan.
- [ ] Swagger UI dapat dibuka pada environment yang disepakati.
- [ ] Base URL staging dan production dicatat tanpa secret.
- [ ] Sanctum/auth flow dan permission dijelaskan.
- [ ] Endpoint webhook/callback marketplace dicatat.
- [ ] Postman collection diekspor tanpa token aktif.
- [ ] Contoh request/response sudah disamarkan.
- [ ] Known limitation API marketplace dicatat.
- [ ] Route penting yang belum masuk OpenAPI diberi status eksplisit.
- [ ] Commit/tag dan image digest yang menghasilkan `api-docs.json` dicatat.
- [ ] Contoh webhook production diuji dengan event ID yang disamarkan dan hasil 2xx.
