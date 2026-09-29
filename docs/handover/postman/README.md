# Postman Collection Cilupbah Super App

Collection ini dibuat otomatis dari:

`storage/api-docs/api-docs.json`

## Cakupan collection

Angka pada collection harus dibaca sebagai berikut:

| Ukuran | Jumlah | Arti |
|---|---:|---|
| OpenAPI path | 101 | Pola URL yang terdokumentasi |
| OpenAPI operation | 140 | Kombinasi method + path yang terdokumentasi |
| Postman request | 140 | Request yang dibuat dari setiap operation OpenAPI |

Ini bukan klaim bahwa seluruh route Laravel berjumlah 140. `route:list` pada
source saat audit memuat route internal, operasi maintenance, health check,
callback, dan route aplikasi lain yang belum seluruhnya menjadi kontrak API
eksternal. Route yang belum masuk OpenAPI sengaja tidak otomatis dimasukkan ke
Postman agar collection tidak mengklaim endpoint yang belum ditinjau.

Sebelum BAST, maintainer harus menentukan route mana yang memang merupakan
kontrak eksternal. Route eksternal yang belum terdokumentasi perlu dianotasi di
OpenAPI lalu collection dibuat ulang.

Tidak ada token, password, atau secret aktif di dalam collection maupun
environment file.

## Cara pakai

1. Import `Cilupbah-Super-App.postman_collection.json` ke Postman.
2. Import salah satu environment:
   - `Cilupbah-Staging.postman_environment.json` untuk staging.
   - `Cilupbah-Production.postman_environment.json` untuk production.
3. Pilih environment yang sesuai.
4. Jalankan endpoint login untuk mendapatkan access token.
5. Simpan token hanya pada variable `accessToken` di Postman, bukan di file
   repository atau chat.

Collection menggunakan bearer token secara otomatis pada endpoint yang
memerlukan autentikasi. Endpoint login tidak menggunakan bearer token.

## Pembaruan

Jika kontrak API berubah, generate ulang dari OpenAPI agar dokumentasi tidak
ditulis manual:

```bash
./scripts/generate-postman-collection
```

Script otomatis memasang converter yang diperlukan, mengatur base URL staging,
menambahkan placeholder bearer token, dan menonaktifkan bearer token pada
endpoint login. Environment production tetap dipilih melalui file environment
Postman, bukan dengan mengubah source collection.
