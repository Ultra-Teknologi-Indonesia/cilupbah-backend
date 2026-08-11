# Shadow Mode — Migrasi Jubelio ke Superapp

Dokumen ini menjelaskan apa itu Shadow Mode, apa saja yang sudah dibangun, cara
memakainya, cara mematikan dan menghapusnya saat migrasi selesai, rencana tahap
stok, dan peta jalan lengkap sampai lepas dari Jubelio (bagian 8).

---

## 1. Prinsip

Shadow Mode menarik order marketplace ke sistem ini **secara paralel** dengan
Jubelio, tanpa memfulfill apa pun. Tujuannya satu: membuktikan datanya cocok
**sebelum** benar-benar pindah.

Dua aturan yang tidak boleh dilanggar selama Shadow Mode:

1. **Sistem ini tidak menulis apa pun ke marketplace.** Tidak stok, tidak harga,
   tidak status, tidak resi. Jubelio tetap satu-satunya penulis.
2. **Order shadow tidak menyentuh stok dan tidak masuk angka operasional atau
   keuangan.** Datanya nyata, tapi peristiwanya tidak terjadi di sini.

Yang perlu diluruskan, karena keduanya sering tertukar: **order shadow ditulis
penuh ke database internal** — header dan itemnya tersimpan di `sales_orders`
lewat jalur yang sama dengan order biasa, hanya ditandai `is_shadow`. Yang tidak
disentuh adalah **stoknya**, dan itu keputusan yang disengaja: selama fase ini
stok internal dibiarkan apa adanya, tidak dimirror dari pergerakan order shadow.

Konsekuensinya dibahas di bagian 6.2 S1 dan harus dibaca sebelum masuk fase
stok: angka stok WMS selama fase shadow tidak bisa dipakai sebagai dasar
apa pun, dan fase stok mulai dari saldo awal yang baru.

Shadow order tanpa pembanding hanya menumpuk data. Yang membuatnya bernilai
adalah rekonsiliasi dan kriteria kelulusan yang disepakati di depan.

---

## 2. Yang sudah dibangun

### 2.1 Kolom database

| Tabel | Kolom | Arti |
|---|---|---|
| `channel_shops` | `is_shadow_mode` | Toko sedang dalam mode shadow |
| `channel_shops` | `shadow_started_at` | Cutoff: batas awal scope perbandingan |
| `channel_shops` | `shadow_last_pulled_at` | Cursor tarik inkremental |
| `channel_shops` | `stock_push_enabled` | Sistem ini boleh menulis stok/harga ke toko ini |
| `channel_shops` | `stock_push_buffer` | Buffer pengaman (unit) yang dikurangkan saat push |
| `channel_shops` | `stock_handover_at` | Kapan kepemilikan stok toko ini diserahterimakan |
| `sales_orders` | `is_shadow` | Order hasil tarik paralel, tidak difulfill di sini |

`shadow_started_at` diisi otomatis saat Shadow Mode dinyalakan lewat API. Untuk
toko yang sudah shadow sebelum migrasi kolom ini ada, nilainya diisi waktu
migrasi dijalankan — **kalau cutoff yang disepakati berbeda (misal jam 00.00
tanggal tertentu), setel manual**, karena kolom inilah batas scope rekonsiliasi.

### 2.2 Pengaman

| Tempat | Perilaku |
|---|---|
| `SyncProductToChannelJob` | Dilewati kalau `stock_push_enabled` mati — **tidak ada push stok/harga ke marketplace** |
| `ShadowOrderGuard` | Order shadow tidak reserve/pick/release stok, dan setiap percobaan dicatat di log |
| `SalesOrder::excludeShadow()` | Dipakai di sync keuangan, settlement, dashboard, laporan, dan list order |
| List order | Order shadow disembunyikan secara default |

**Order dan stok dikendalikan flag terpisah.** `is_shadow_mode` mengatur mode
order (tarik paralel, tidak difulfill); `stock_push_enabled` mengatur boleh
tidaknya sistem ini menulis stok ke marketplace. Keduanya sengaja dipisah karena
rencananya order pindah lebih dulu, stok menyusul per toko — kalau digabung,
cutover order akan langsung menyalakan push stok padahal stok belum siap.

Menyalakan Shadow Mode otomatis mematikan `stock_push_enabled`. Mematikan
Shadow Mode **tidak** menyalakannya kembali — itu langkah terpisah dan disengaja
(bagian 6).

`stock_push_enabled` juga berfungsi sebagai **kill switch push stok per toko**:
mematikannya menghentikan push seketika, tanpa deploy.

### 2.3 Command

| Command | Fungsi |
|---|---|
| `channel:pull-shadow-orders` | Tarik order toko shadow (terjadwal tiap 15 menit) |
| `channel:shadow-reconcile` | Bandingkan order shadow dengan export sistem lama |
| `channel:shadow-promote` | Keluarkan order tertentu dari shadow, untuk gladi resik fulfillment |
| `channel:shadow-off` | Matikan Shadow Mode saat cutover order |
| `channel:shadow-purge` | Hapus arsip order shadow setelah selesai |
| `channel:stock-reconcile` | Bandingkan stok WMS vs listing marketplace vs sistem lama (tanpa mengirim) |
| `channel:stock-handover` | Aktifkan push stok untuk satu toko (serah terima stok) |
| `channel:stock-rollback` | Hentikan push stok — kembalikan kepemilikan ke sistem lama |

Semua command yang mengubah data punya `--dry-run`; yang destruktif (`shadow-purge`)
default-nya hanya menampilkan rencana dan butuh `--force`.

### 2.4 API

- `PATCH` pengaturan toko menerima `is_shadow_mode`.
- List order menerima `filter[shadow]=only` (hanya shadow) atau `all` (keduanya).
  Tanpa filter, order shadow tidak muncul.

---

## 3. Cara pakai

### 3.1 Menyalakan Shadow Mode

Lewat UI Integrasi Channel (toggle per toko), atau lewat API pengaturan toko.
`shadow_started_at` akan terisi otomatis dan cursor direset.

### 3.2 Tarik order

Terjadwal tiap 15 menit, inkremental berbasis cursor. Manual:

```bash
# Uji dulu tanpa menyimpan — jalur kode sebenarnya, lalu di-rollback
php artisan channel:pull-shadow-orders --dry-run --shop=778899

# Tarik normal (inkremental, dari cursor)
php artisan channel:pull-shadow-orders

# Backfill sejak cutoff toko, untuk membangun baseline pembanding
php artisan channel:pull-shadow-orders --full

# Backfill rentang tertentu (WIB). Dengan --to, cursor tidak dimajukan
php artisan channel:pull-shadow-orders --from="2026-07-15" --to="2026-08-01"
```

Perilaku yang perlu diketahui:

- Cursor hanya maju kalau run **sukses** dan jendelanya berakhir di "sekarang".
  Run yang gagal diulang dari titik yang sama, jadi tidak ada lubang data.
- Jendela mundur 30 menit dari cursor sebagai toleransi clock skew dan webhook
  telat. Order yang tertarik dua kali di-upsert, tidak diduplikasi.
- Shopee membatasi rentang 15 hari per request; backfill panjang dipecah
  otomatis jadi window 14 hari.
- **Cutoff adalah lantai keras.** Klien memutuskan histori sebelum sistem ini
  tidak dimigrasi, jadi jendela apa pun — termasuk `--from` manual — dipangkas
  ke `shadow_started_at`, dan pemangkasannya dilaporkan, bukan diam-diam.
- Toko yang gagal tidak menghentikan toko lain; command keluar dengan status
  gagal supaya monitoring menangkapnya.

### 3.3 Rekonsiliasi

Sisi sistem lama dibaca dari CSV export (belum ada kredensial API ke Jubelio).

| Kolom | Wajib | Fungsi |
|---|---|---|
| `channel_order_no` | ya | Kunci join ke order di sistem ini. **Harus nomor order marketplace**, bukan nomor internal sistem lama |
| `grand_total` | tidak | Deteksi selisih nilai |
| `channel_status` | tidak | Deteksi selisih status |
| `shop_id` | tidak | Memisahkan baris per toko kalau satu file berisi banyak toko |

Kalau file berisi banyak toko tapi tidak punya kolom `shop_id`, baris milik toko
lain akan terhitung sebagai selisih. Pakai satu file per toko dengan `--shop`,
atau minta kolom `shop_id` disertakan — command akan memperingatkan kalau
kondisinya terdeteksi.

```bash
# Ringkasan sisi kita saja (baseline harian)
php artisan channel:shadow-reconcile --date=2026-08-10

# Bandingkan dengan export sistem lama
php artisan channel:shadow-reconcile --date=2026-08-10 --jubelio=/path/export.csv

# Rentang, dan toleransi selisih nilai dilonggarkan
php artisan channel:shadow-reconcile --from=2026-08-01 --to=2026-08-10 \
  --jubelio=/path/export.csv --tolerance=100
```

Keluarannya per toko: jumlah order kedua sisi, order yang hanya ada di satu
sisi, selisih nilai, selisih status, dan match rate. Hasil ditulis ke
`storage/app/shadow-reconcile/` supaya trennya bisa diikuti antar hari.

### 3.4 Kriteria kelulusan (harus disepakati sebelum cutover)

Angka di bawah ini usulan, bukan aturan — tapi **harus ada angkanya**, kalau
tidak keputusan cutover jadi soal perasaan:

- Match rate order **≥ 99.5%** per toko, **14 hari berturut-turut**.
- Tidak ada order yang hilang di sistem ini (`missing_in_ours` = 0) di 7 hari
  terakhir.
- Selisih nilai total ≤ **0.1%** per toko.
- Semua selisih yang tersisa **bisa dijelaskan** penyebabnya, bukan sekadar
  kecil.

### 3.5 Gladi resik fulfillment (wajib sebelum cutover)

Shadow order hanya membuktikan **sisi baca**. Jalur tulis — minta AWB, cetak
label, tandai dikirim, ajukan batal — belum pernah dijalankan sungguhan, padahal
itu jalur paling berisiko: AWB gagal terbit berarti order tidak bisa dikirim,
dan dampaknya ke pembeli dan skor toko terasa dalam hitungan jam.

Ambil 5–10 order nyata di satu toko kecil, keluarkan dari shadow, lalu fulfill
penuh lewat sistem ini sampai barang terkirim. Toko tetap shadow, jadi push stok
tetap mati dan risikonya terbatas pada order yang dipilih.

```bash
php artisan channel:shadow-promote --order=SO-000123 --dry-run
php artisan channel:shadow-promote --order=SO-000123 --order=SO-000124
```

Konsekuensi yang harus disengaja: order yang dipromosikan terpotong stoknya di
dua sistem, dan sistem lama juga akan mencoba menerbitkan AWB-nya. **Tandai
order pilot selesai manual di sistem lama** dan sesuaikan selisih stoknya.
Volumenya kecil, tapi harus direncanakan — bukan ditemukan belakangan.

Sepuluh order yang tuntas dari terima sampai terkirim lebih meyakinkan daripada
sebulan shadow order.

---

## 4. Mematikan Shadow Mode saat cutover

```bash
# Lihat dampaknya dulu
php artisan channel:shadow-off --shop=778899 --promote --dry-run

# Jalankan
php artisan channel:shadow-off --shop=778899 --promote
```

Yang terjadi:

- **Order shadow yang masih terbuka** (status `pending`/`reserved`, belum batal)
  dipromosikan jadi order sungguhan bila `--promote` dipakai. Untuk order yang
  berstatus `reserved`, stok direservasi tanpa enforce — kekurangan stok tercatat
  sebagai selisih yang bisa ditindaklanjuti, bukan menggagalkan cutover.
- **Order yang sudah dipick/dipack/dikirim/dibatalkan** di sistem lama tetap jadi
  arsip shadow. Fulfillment-nya tidak pernah terjadi di sini, jadi tidak boleh
  ikut masuk angka operasional.
- `is_shadow_mode` toko dimatikan.

Tanpa `--promote`, semua order shadow tetap arsip dan tidak akan difulfill di
sistem ini — pakai ini kalau order in-flight diselesaikan di Jubelio.

**Keputusan yang harus diambil sebelum menjalankan:** order yang sedang berjalan
saat cutover diselesaikan di sistem mana. Umumnya jawabannya "in-flight selesai
di Jubelio, order baru di superapp" — itu berarti **tanpa** `--promote`.

---

## 5. Menghapus setelah migrasi selesai

### 5.1 Hapus arsip data

Destruktif, jadi tanpa `--force` command hanya menampilkan rencana.

```bash
# Lihat rencana
php artisan channel:shadow-purge

# Hapus arsip lama saja
php artisan channel:shadow-purge --before=2026-09-01 --force
```

Penghapusan dilakukan per order dalam transaksi masing-masing. Order yang
ternyata punya dokumen turunan dilewati dan dilaporkan, bukan menggagalkan
seluruh batch.

### 5.2 Hapus kode shadow

Lakukan **setelah** semua toko selesai diserahkan dan arsipnya dihapus. Jangan
lebih awal — selama masih ada toko yang belum diserahkan, `is_shadow_mode` masih
dipakai sebagai kill switch push stok.

Backend — hapus file:

- `Modules/Channel/app/Console/Commands/PullShadowOrdersCommand.php`
- `Modules/Channel/app/Console/Commands/ShadowReconcileCommand.php`
- `Modules/Channel/app/Console/Commands/ShadowOffCommand.php`
- `Modules/Channel/app/Console/Commands/ShadowPromoteCommand.php`
- `Modules/Channel/app/Console/Commands/ShadowPurgeCommand.php`
- `Modules/Channel/app/Console/Commands/StockReconcileCommand.php`
- `Modules/Channel/app/Exceptions/UnsupportedShadowChannelException.php`
- `Modules/Sales/app/Support/ShadowOrderGuard.php`

**Jangan** hapus `StockHandoverCommand`, `StockRollbackCommand`,
`ChannelLiveStockReader`, dan kolom `stock_push_enabled` / `stock_push_buffer`
— itu bukan perkakas migrasi. Kill switch push stok per toko dan pembaca stok
listing tetap berguna setelah migrasi selesai, misal saat menonaktifkan satu
toko sementara atau menyelidiki selisih stok.

Backend — hapus bagian shadow di file berikut:

- `routes/console.php` — jadwal `channel:pull-shadow-orders`
- `Modules/Channel/app/Services/ChannelSyncSettingService.php` — `withInboundBypass`, `inboundBypassActive`, cek bypass di `isEnabled`
- `Modules/Channel/app/Services/ChannelService.php` — `applyShadowCutoff`
- `Modules/Channel/app/Models/ChannelShop.php` — kolom shadow di `$fillable` dan `$casts`
- `Modules/Channel/app/Http/Resources/ChannelShopResource.php`
- `Modules/Channel/app/Http/Requests/UpdateChannelShopRequest.php`
- `Modules/Channel/app/Repositories/ChannelShopRepository.php` — `markShadowPulledUpTo`
- `Modules/Channel/app/Jobs/SyncProductToChannelJob.php` — guard `is_shadow_mode`
- `Modules/Sales/app/Models/SalesOrder.php` — `scopeExcludeShadow`, `scopeOnlyShadow`, `is_shadow`
- `Modules/Sales/app/Services/SalesOrderService.php` — `promoteFromShadow`, `isPromotableFromShadow`, tiga pemanggilan `ShadowOrderGuard`, penetapan `is_shadow` di `upsertFromChannel`
- `Modules/Sales/app/Repositories/SalesOrderRepository.php` — `applyShadowFilter`, default `excludeShadow`, `is_shadow` saat upsert
- `Modules/Sales/app/Http/Resources/SalesOrderResource.php`
- `Modules/Sales/app/Console/Commands/SyncOrderFinance.php`, `Modules/Sales/app/Services/SettlementSyncService.php`, `Modules/Dashboard/app/Repositories/DashboardRepository.php`, `Modules/Report/app/Repositories/ReportRepository.php` — pemanggilan `excludeShadow()`

Frontend (`cilupbah-frontend`):

- `src/lib/status.ts` — domain `order-origin`
- `src/types/pesanan/order.ts` — `Order.is_shadow`, `OrderListParams.shadow`
- `src/types/channel/channel.types.ts` — kolom shadow
- `src/services/pesanan/order.service.ts` — `filter[shadow]`
- `src/services/channel/channel.service.ts` — `is_shadow_mode`
- `src/components/dashboard/pesanan/order-filters.tsx` — `SHADOW_OPTIONS`, `FilterState.shadow`
- `src/components/dashboard/pesanan/pesanan-view.tsx` — parameter `shadow`
- `src/components/dashboard/pesanan/order-card.tsx` — badge Shadow
- `src/components/dashboard/integrasi-channel/integrasi-channel-view.tsx` — toggle
- `src/lib/channel/group-stores.ts` — `isShadowMode`

Terakhir, migrasi untuk drop kolom `sales_orders.is_shadow`, `channel_shops.is_shadow_mode`,
`shadow_started_at`, `shadow_last_pulled_at`. **Jalankan setelah arsip dihapus** —
begitu kolomnya hilang, order shadow tidak bisa dibedakan lagi dari order asli.

---

## 6. Tahap berikutnya: stok

### 6.1 Kendala: toko tidak bisa ditutup

Cara paling umum memindahkan kepemilikan stok adalah membekukan transaksi
sebentar, ambil snapshot, lalu pindah penulis. Di sini itu tidak mungkin.

Kabar baiknya, **jendela pemeliharaan sebenarnya tidak dibutuhkan.** Yang wajib
dijamin bukan "tidak ada transaksi", melainkan:

> **Pada satu waktu, hanya boleh ada satu sistem yang menulis stok ke satu toko.**

Bahaya sesungguhnya bukan angka yang basi beberapa menit, tapi **dua penulis**.
Kalau Jubelio dan superapp sama-sama push, angka di marketplace akan berosilasi
dan yang menang adalah yang menulis terakhir — bukan yang benar. Itu jauh lebih
merusak daripada jeda beberapa menit tanpa penulis.

Karena stok marketplace disetel per toko dan per listing, perpindahan bisa
dilakukan **bertahap per toko**, bukan sekali serentak. Blast radius-nya jadi
satu toko, bukan seluruh bisnis.

### 6.2 Urutan yang disarankan

**S0 — Prasyarat.** Jangan mulai stok untuk satu toko sebelum order toko itu
lulus kriteria di bagian 3.4. Kebenaran stok bergantung pada order terpotong
dengan benar; kalau ordernya belum dipercaya, selisih stok tidak bisa
didiagnosis.

**S1 — Tetapkan saldo awal di titik ini, bukan sebelumnya.**

Selama fase order shadow, **stok internal sengaja tidak disentuh** (bagian 1).
Konsekuensinya harus disadari: order shadow yang dikirim lewat sistem lama
mengurangi stok fisik di gudang, tapi tidak mengurangi angka di WMS. Jadi angka
stok WMS selama fase shadow **tidak bisa dipakai sebagai dasar apa pun** —
selisihnya bertambah setiap hari sebesar volume penjualan.

Artinya fase stok tidak mewarisi saldo dari fase order. Saldo awal ditetapkan
dari nol di titik ini, lewat stock opname atau impor saldo dari sistem lama, dan
**itu yang jadi titik nol rekonsiliasi**.

Dua hal yang mengikuti dari situ:

- **Input inbound dobel selama fase order shadow tidak membangun saldo yang
  terpakai** — apa pun yang diinput akan tertimpa saat saldo awal ditetapkan.
  Kerja dobel baru punya nilai setelah S1 selesai. Ini keputusan biaya
  operasional yang layak ditinjau ulang dengan tim admin.
- Setelah S1, jangan lagi mengejar kesamaan saldo pada satu detik yang sama —
  itu memang mustahil tanpa menutup toko. Yang dipantau: **pergerakan** per SKU
  per hari di kedua sistem, dan **saldo akhir hari** pada jam paling sepi. Sisa
  selisih setelah pergerakannya cocok dicatat sebagai satu penyesuaian pembukaan
  dengan alasan khusus, lalu ditutup — jangan diperdebatkan berhari-hari.

**S2 dan S3 — Shadow stock + dry-run push, dalam satu command.**

`channel:stock-reconcile` membandingkan tiga sisi sekaligus: **WMS internal vs
angka live di listing marketplace vs sistem lama (CSV, opsional).** Kolom kedua
yang paling menentukan — itulah angka yang dilihat pembeli.

Command ini sekaligus **dry-run push**, dan itu bukan kebetulan: angka WMS yang
ditampilkan dihitung lewat `ChannelStockResolver` — jalur yang sama persis
dipakai push sungguhan, termasuk mode sumber stok, perhitungan bundle, dan
buffer. Kalau angkanya salah di sini, ia akan salah juga saat dikirim; bedanya
di sini tidak ada yang terkirim.

```bash
# Semua toko aktif, tampilkan semua SKU
php artisan channel:stock-reconcile

# Satu toko, hanya yang selisih, plus pembanding sistem lama
php artisan channel:stock-reconcile --shop=778899 --only-diff \
  --jubelio=/path/stok-jubelio.csv

# Uji cepat 20 produk dulu sebelum menjalankan penuh
php artisan channel:stock-reconcile --shop=778899 --limit=20

# Toleransi 1 unit dianggap cocok
php artisan channel:stock-reconcile --tolerance=1
```

CSV sistem lama butuh kolom `sku` dan `qty`. Hasil ditulis ke
`storage/app/stock-reconcile/` supaya trennya bisa diikuti antar hari. Jalankan
harian, dan **jangan lanjut ke S4 sebelum match rate memenuhi bagian 6.4.**

Kolom "Listing tak terbaca" perlu diperhatikan: itu listing yang stoknya gagal
diambil dari marketplace. Kalau angkanya besar, hasil rekonsiliasi belum bisa
dipercaya — perbaiki dulu penyebabnya sebelum menilai match rate.

**S4 — Serah terima per toko.** Untuk satu toko, pada jam paling sepi:

1. Matikan sinkronisasi stok Jubelio **untuk toko itu** (toko tetap buka).
2. Segera aktifkan push dari sistem ini untuk toko itu:

```bash
# Lihat rencananya dulu
php artisan channel:stock-handover --shop=778899 --buffer=3 --dry-run

# Jalankan (akan menanyakan konfirmasi bahwa sync Jubelio sudah dimatikan)
php artisan channel:stock-handover --shop=778899 --buffer=3
```

Command ini menolak jalan kalau toko masih dalam mode shadow order — cutover
order harus selesai lebih dulu. Setelah aktif, resync stok awal langsung
diantrikan supaya angka di marketplace segera dikoreksi.

Jeda antara langkah 1 dan 2 hitungan menit. Selama jeda, angka di marketplace
adalah angka terakhir dari Jubelio — masih mendekati benar. Mulai dari toko
dengan omzet paling kecil, amati 24–48 jam, baru lanjut ke toko berikutnya.

**S5 — Buffer pengaman.** Opsi `--buffer=N` mengurangi N unit dari setiap angka
yang dikirim, untuk meredam oversell selama angka belum stabil. Buffer
diterapkan di `ChannelStockResolver`, jadi semua jalur push memakainya —
termasuk pratinjau di `channel:stock-reconcile`, sehingga yang Anda lihat saat
pratinjau benar-benar yang akan dikirim.

Lepas buffer setelah rekonsiliasi bersih:

```bash
php artisan channel:stock-handover --shop=778899 --buffer=0 --force
```

**S6 — Akhiri kerja dobel per scope.** Begitu satu toko diserahkan, inbound dan
penyesuaian untuk stok itu **hanya** di superapp. Kerja dobel tanpa tanggal
akhir selalu ditinggalkan diam-diam oleh tim admin, dan datanya jadi sampah yang
merusak kepercayaan pada sistem baru. Setiap scope yang masih dobel harus punya
tanggal berakhir yang tertulis.

### 6.3 Rollback

Kalau setelah serah terima ada yang tidak beres:

```bash
php artisan channel:stock-rollback --shop=778899
```

Push dari sistem ini berhenti seketika (dijamin oleh guard di
`SyncProductToChannelJob`). Command ini sengaja **tanpa konfirmasi** — arahnya
aman, yang mahal justru menundanya — dan sengaja dipisah dari `stock-handover`
supaya di situasi tertekan namanya gampang diingat. Tanpa `--shop`, semua toko
dihentikan sekaligus.

Langkah keduanya manual dan wajib: **nyalakan lagi sinkronisasi stok Jubelio**
untuk toko itu, supaya marketplace tetap punya satu penulis.

Satu command, tanpa deploy, tanpa menutup toko. Karena itu **jangan hapus konfigurasi
sinkronisasi di Jubelio** sampai semua toko selesai diserahkan dan stabil —
biaya menyimpannya nol, biaya kehilangannya sangat mahal.

### 6.4 Kriteria kelulusan stok (per toko)

- Selisih WMS vs angka live listing **≤ 1 unit untuk ≥ 99% SKU**, 7 hari berturut-turut.
- **Tidak ada** SKU dengan selisih besar yang tidak bisa dijelaskan.
- Hasil dry-run push cocok dengan angka live untuk **≥ 99% SKU** selama minimal
  3 hari sebelum serah terima.
- Tidak ada oversell baru yang berasal dari selisih data selama masa pengamatan.

### 6.5 Yang masih harus diputuskan tim

- Toko mana yang jadi kelinci percobaan pertama (usul: omzet terkecil).
- Jam serah terima per toko (jam paling sepi berdasarkan data order).
- Besar buffer pengaman di hari-hari pertama.
- Tanggal berakhir kerja dobel per scope.
- Siapa yang membaca laporan rekonsiliasi harian dan siapa yang berwenang
  menekan tombol rollback.

---

## 7. Yang belum ada

Supaya tidak ada yang mengira ini sudah lengkap:

- **Belum ada satu pun command di dokumen ini yang pernah dijalankan terhadap
  data sungguhan.** Test otomatis sudah ditulis tapi belum pernah dieksekusi —
  butuh `composer install` dan database test. Jalankan test dulu, baru command
  dengan `--dry-run` / `--limit` di satu toko.
- **Integrasi API ke Jubelio belum ada** — rekonsiliasi masih lewat CSV export.
  Ini terhalang kredensial, bukan pekerjaan yang bisa diselesaikan dari sisi kode.
- **Pembacaan stok live belum diuji terhadap respons asli** ketiga marketplace.
  Bentuk respons stok Shopee/TikTok/Lazada dibaca defensif dengan beberapa
  kemungkinan nama field. **Ini titik paling mungkin bermasalah di percobaan
  pertama** — kalau kolom "Listing tak terbaca" tinggi, yang perlu disesuaikan
  biasanya pemetaan field-nya, bukan datanya.
- **Penanda diskontinuitas baru tersedia di API** (`data_starts_at` di ringkasan
  dashboard); tampilan di UI belum dibuat.
- **Serah terima stok sengaja tidak punya UI** — command dengan konfirmasi lebih
  aman daripada toggle yang bisa terpencet.
- **Gap analysis fitur Jubelio belum dikerjakan** (bagian 8, G0). Ini pekerjaan
  wawancara tim admin, bukan pekerjaan kode, dan hasilnya bisa mengubah jadwal.

---

## 8. Peta jalan: dari shadow order sampai lepas dari Jubelio

Enam tahap. Yang menentukan lamanya bukan tahap order atau stok, melainkan ekor
settlement di tahap 5 — settlement marketplace mendarat 7–30+ hari setelah
order, dan Jubelio tidak bisa dimatikan sebelum yang terakhir tuntas.

### G0 — Persiapan (sebelum shadow order dinyalakan)

| Langkah | Sifat |
|---|---|
| Gap analysis: fitur apa yang dipakai tim admin harian di Jubelio tapi belum ada di sini | wawancara, bukan kode |
| Sepakati cutoff per toko dan siapa PIC-nya | keputusan |
| Sepakati kriteria kelulusan order (bagian 3.4) dan stok (bagian 6.4) | keputusan |
| Tinjau ulang kerja dobel input stok selama fase order — belum berguna sampai G4/S1 | keputusan |
| Jalankan test dan uji semua command dengan `--dry-run` di satu toko | teknis |

Gap analysis paling mahal kalau ditunda. Kalau baru ketahuan saat cutover,
pilihannya tinggal dua-duanya buruk: tunda cutover, atau paksa tim bekerja tanpa
alat.

### G1 — Shadow order (2–4 minggu)

Nyalakan Shadow Mode per toko, biarkan tarik inkremental berjalan, rekonsiliasi
harian. Sistem ini belum menulis apa pun ke marketplace.

**Keluar dari tahap ini kalau:** kriteria bagian 3.4 terpenuhi per toko.

### G2 — Gladi resik fulfillment (beberapa hari)

`channel:shadow-promote` untuk 5–10 order nyata di satu toko kecil, fulfill
penuh sampai barang terkirim. Ini menutup satu-satunya lubang yang tidak bisa
ditutup shadow order: jalur tulis AWB, label, dan ship belum pernah dijalankan.

**Keluar dari tahap ini kalau:** semua order pilot terkirim tanpa intervensi
manual di luar yang direncanakan.

### G3 — Cutover order, per toko

`channel:shadow-off`. Sejak titik ini order difulfill di sistem ini, tapi
**stok masih milik Jubelio** — push stok tetap mati karena flagnya terpisah.

Keputusan yang harus sudah diambil sebelum menjalankan: order in-flight
diselesaikan di mana (umumnya di Jubelio, artinya tanpa `--promote`).

### G4 — Cutover stok, per toko (bagian 6)

Rekonsiliasi stok harian → serah terima satu toko di jam sepi → amati 24–48 jam
→ toko berikutnya. Toko tidak ditutup; yang dijamin adalah satu penulis.

**Keluar dari tahap ini kalau:** semua toko diserahkan dan kriteria bagian 6.4
terpenuhi, tanpa rollback dalam 2 minggu terakhir.

### G5 — Ekor: retur dan settlement (1–2 bulan)

Tahap terpanjang, dan satu-satunya yang tidak bisa dipercepat.

- Retur untuk order pra-cutover diselesaikan di Jubelio; pasca-cutover di sini.
  Keduanya harus dipantau selama masa transisi.
- Settlement pra-cutover terus mendarat di Jubelio. Tentukan sistem mana yang
  jadi buku resmi untuk tiap periode, dan bagaimana konsolidasinya untuk
  akuntansi dan pajak.
- Kepemilikan listing, harga, dan promo berpindah penuh ke sini. Kalau ini tidak
  diputuskan, tim admin akan diam-diam terus memakai Jubelio dan divergensi
  masuk lewat pintu belakang.

**Keluar dari tahap ini kalau:** settlement terakhir untuk order pra-cutover
sudah mendarat dan terekonsiliasi.

### G6 — Decommission dan bersih-bersih

Urutannya penting:

1. Hypercare 2–4 minggu: pengawasan ketat, PIC bernama, wewenang rollback jelas.
2. Ekspor final data Jubelio sebagai arsip (histori tidak dimigrasi ke sini —
   keputusan klien — jadi arsip inilah satu-satunya jejak data lama).
3. **Cabut otorisasi aplikasi Jubelio di seller center Shopee/TikTok/Lazada.**
   Mematikan sync lewat pengaturan Jubelio hanya jaminan lunak; selama
   otorisasinya hidup, satu klik atau satu bug bisa membuatnya menulis stok
   lagi. Ini satu-satunya jaminan keras bahwa penulis tinggal satu.
4. Akhiri langganan Jubelio dan cabut akses user.
5. Hapus arsip order shadow (`channel:shadow-purge`), lalu hapus kode shadow
   (checklist bagian 5.2), lalu migrasi drop kolom.

Langkah 3 baru boleh dilakukan **setelah** hypercare lewat, karena mencabut
otorisasi juga menutup jalan rollback.

### Ringkasan jalur rollback per tahap

| Tahap | Kalau bermasalah |
|---|---|
| G1 | Matikan Shadow Mode. Tidak ada dampak — sistem ini belum menulis apa pun |
| G2 | Selesaikan order pilot manual di Jubelio |
| G3 | Nyalakan lagi Shadow Mode toko itu; order kembali dikerjakan di Jubelio |
| G4 | `channel:stock-rollback --shop=...`, lalu nyalakan lagi sync stok Jubelio |
| G5 | Tidak ada rollback teknis — ini soal kesepakatan buku resmi |
| G6 | Setelah otorisasi dicabut, tidak ada jalan kembali. Karena itu urutannya begitu |
