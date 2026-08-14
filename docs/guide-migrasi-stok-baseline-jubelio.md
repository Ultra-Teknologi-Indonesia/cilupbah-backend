# PANDUAN LENGKAP & RUNBOOK MIGRASI STOK BASELINE (JUBELIO → CILUPBAH SUPERAPP)

> **Dokumen Transfer Knowledge Developer**  
> **Status:** Siap Eksekusi  
> **Target Audience:** Backend Developer, DevOps / Sysadmin, Lead Engineer, QA  

---

## 1. Prinsip Utama & Arsitektur Stok

### 1.1 Model Stok Dua Lapis (Two-Layer Ledger)
Di Cilupbah Superapp, stok **tidak disimpan sebagai angka statis tunggal**, melainkan dua lapis:
1. **`on_hand` (Stok Fisik)**: Stok riil yang berada di rak tertentu (`location_bins`). Angka ini **hanya** bertambah saat Putaway/Inbound dan berkurang saat Selesai Packing / Selesaikan Langsung.
2. **`on_order` (Akrual Komitmen Pesanan)**: Stok yang sedang dipesan oleh pembeli aktif (`status = reserved`). Bertambah saat order masuk, berkurang saat order dipick/selesai/dibatalkan.
3. **`available` (Stok Siap Jual)**: Dihitung dinamis dengan rumus:
   $$\text{available} = \max(0, \text{on\_hand} - \text{on\_order})$$

### 1.2 Mengapa Mengambil `Qty Aktual` (Bukan `Qty On Hand`) dari Excel?
Di file export Jubelio (`besar.xlsx` / `kecil.xlsx`):
- `Qty On Hand`: Total stok kotor termasuk barang yang sudah diambil picker dari rak tapi belum selesai dipacking di Jubelio.
- `Qty Aktual`: Stok fisik bersih yang **benar-benar masih duduk di atas rak**.
- **Keputusan**: Kita **wajib mengimpor `Qty Aktual`**. Barang yang selisih sudah dialokasikan untuk order Jubelio lama. Jika kita mengimpor `Qty On Hand`, barang tersebut akan terhitung ganda (*double-promising / oversell*).
- Angka `on_order` **tidak diimpor dari Excel**, melainkan dihitung otomatis oleh sistem dari pesanan-pesanan baru sejak webhook aktif.

### 1.3 Pengaman 5 Sumbu (5 Sync Axes Guardrails)
Toko di marketplace **tidak perlu ditutup / diliburkan**. Sistem kita memiliki 5 gembok independen pada tabel `channel_shops`:

| Sumbu Sinkronisasi | Nilai Saat Migrasi | Perilaku & Pengaman |
|---|:---:|---|
| `order_sync_enabled` | **TRUE** | Webhook intake order dari marketplace aktif. |
| `is_shadow_mode` | **FALSE** | Mode "Produksi Hening": order nyata masuk, `on_order` berjalan. |
| `stock_push_enabled` | **FALSE** | **GEMBOK STOK**: Sistem menolak mengirim angka stok ke marketplace. |
| `catalog_push_enabled` | **FALSE** | **GEMBOK KATALOG**: Sistem menolak mengubah produk/harga di marketplace. |
| `fulfillment_push_enabled` | **FALSE** | **GEMBOK STATUS**: Sistem menolak panggil RTS / AWB / driver ke marketplace. |

> **Jaminan Keamanan:** Selama `stock_push_enabled = false` dan `fulfillment_push_enabled = false`, Jubelio tetap menjadi satu-satunya pemegang otoritas marketplace. Cilupbah bertindak sebagai *mirroring* & pencatatan WMS internal tanpa risiko kebocoran ke channel.

---

## 2. Perencanaan Waktu (Execution Timeline)

| Fase | Waktu / Jadwal | Aktivitas Utama |
|---|---|---|
| **H-3** | Siang hari | Jalankan simulasi *Dry-Run* pertama kali untuk memetakan daftar SKU & Rak yang belum terdaftar. |
| **H-2 s/d H-1** | Fleksibel | Daftarkan master data SKU yang kurang dan jalankan seeder rak gudang. |
| **H-0 (Eksekusi)** | **Malam Hari (Jam Lengang Gudang)**<br>*(Contoh: 21.00 - 23.00 WIB setelah cut-off packing Jubelio)* | 1. Ambil file snapshot export Jubelio terbaru.<br>2. Upload file ke server.<br>3. Jalankan `inventory:import-baseline --commit --zero-missing`.<br>4. Aktifkan webhook toko (`channel:shadow-off`). |
| **H+1 s/d H+7** | Harian | Operasional **Entri Ganda (Dual Entry)**: Admin memproses pesanan di Jubelio dan Cilupbah. Jalankan `channel:pull-orders` harian sebagai jaring pengaman. |

---

## 3. Step-by-Step Runbook Eksekusi

### Langkah 1: Copy File Excel ke Server / Pod Production
Letakkan file `kecil.xlsx` dan `besar.xlsx` ke dalam pod `cilupbah-app`:

```bash
# Dapatkan nama pod app
POD_NAME=$(kubectl get pods -n cilupbah -l app=cilupbah-app -o jsonpath='{.items[0].metadata.name}')

# Copy file ke pod
kubectl cp /path/to/kecil.xlsx cilupbah/${POD_NAME}:/tmp/kecil.xlsx
kubectl cp /path/to/besar.xlsx cilupbah/${POD_NAME}:/tmp/besar.xlsx
```

---

### Langkah 2: Jalankan Simulasi Dry-Run (Validasi Awal)
Perintah ini **100% aman (tidak menulis ke database)** dan akan menghasilkan file laporan CSV lengkap:

```bash
# Simulasi Gudang Kecil (WH-KECIL)
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php -d memory_limit=1024M artisan inventory:import-baseline /tmp/kecil.xlsx --location=WH-KECIL

# Simulasi Gudang Pusat (WH-PUSAT)
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php -d memory_limit=1024M artisan inventory:import-baseline /tmp/besar.xlsx --location=WH-PUSAT
```

Di akhir output terminal, akan muncul URL download laporan:
```text
===============================================================
LAPORAN LENGKAP TELAH DIBUAT
File Path : /var/www/cilupbah/storage/app/public/baseline-reports/baseline_report_WH-KECIL_20260814_..._DRYRUN.csv
Download  : https://<domain-api>/storage/baseline-reports/baseline_report_WH-KECIL_20260814_..._DRYRUN.csv
===============================================================
```
👉 **Unduh file CSV tersebut via browser** untuk memeriksa daftar baris yang ditolak.

---

### Langkah 3: Bersihkan Masalah Data (Berdasarkan Hasil Dry-Run)
Sebelum melakukan *commit*, pastikan masalah pada laporan CSV sudah diselesaikan:
1. **Jika ada `SKU tidak terdaftar`**: Daftarkan produk/varian SKU tersebut di menu Master Produk atau via import katalog.
2. **Jika ada `Kode rak belum ada di sistem`**: Jalankan seeder layout rak:
   ```bash
   kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan db:seed --class="Modules\Warehouse\Database\Seeders\WhKecilBinLayoutSeeder"
   kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan db:seed --class="Modules\Warehouse\Database\Seeders\WhPusatBinLayoutSeeder"
   ```

---

### Langkah 4: Eksekusi Tulis Stok Baseline ke Database (`--commit`)
Jalankan perintah commit di jam lengang gudang:

```bash
# 1. Eksekusi Gudang Kecil
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php -d memory_limit=1024M artisan inventory:import-baseline /tmp/kecil.xlsx --location=WH-KECIL --commit --zero-missing --chunk=1000

# 2. Eksekusi Gudang Pusat
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php -d memory_limit=1024M artisan inventory:import-baseline /tmp/besar.xlsx --location=WH-PUSAT --commit --zero-missing --chunk=1000
```

> **Penjelasan Parameter:**
> - `--commit` : Menerapkan perubahan ke database (membuat dokumen `StockAdjustment` saldo awal, mengupdate `on_hand`, dan mencatat mutasi ledger).
> - `--zero-missing` : Otomatis menolkan sisa stok lama di sistem yang tidak tercantum di file Excel Jubelio.
> - `--chunk=1000` : Memproses transaksi database per 1.000 baris agar tidak mengunci (*lock*) database secara berlebihan.

---

### Langkah 5: Aktifkan Mode Produksi Hening (Non-Shadow Mode)
Setelah baseline masuk, matikan status shadow toko agar order masuk mencatat `on_order`:

```bash
# Matikan shadow mode untuk semua toko
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan channel:shadow-off

# Verifikasi status 5 sumbu pengaman
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker --execute="
\Modules\Channel\Models\ChannelShop::where('is_active', true)->get(['shop_name', 'is_shadow_mode', 'stock_push_enabled', 'fulfillment_push_enabled'])->dump();
"
```
*(Pastikan `is_shadow_mode = false`, `stock_push_enabled = false`, dan `fulfillment_push_enabled = false`)*.

---

### Langkah 6: Jaring Pengaman Webhook Berkala (Catch-up Ingestion)
Pasang cron job atau jalankan manual setiap hari untuk menarik order yang mungkin terlewat dari webhook:

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan channel:pull-orders --hours=24
```

---

## 4. Penanganan Masalah & Troubleshooting (Failure Handling)

### Kasus 1: Error `Allowed memory size exhausted` saat Impor
- **Penyebab:** Limit memori PHP default CLI terlalu kecil (misal 256M).
- **Solusi:** Tambahkan flag `-d memory_limit=1024M`:
  ```bash
  php -d memory_limit=1024M artisan inventory:import-baseline ...
  ```

### Kasus 2: Error `Kode rak milik gudang lain`
- **Penyebab:** Di file Excel `kecil.xlsx` terdapat kode rak milik Gudang Pusat (atau sebaliknya).
- **Solusi:** Buka laporan CSV hasil dry-run, pisahkan baris rak tersebut ke file gudang yang sesuai.

### Kasus 3: Terjadi Selisih Stok Selama Masa Paralel (Drift)
- **Penyebab:** Human error operator (lupa klik Selesaikan Langsung di Cilupbah atau salah pilih rak).
- **Solusi:** 
  1. Jangan panik, gunakan menu **Penyesuaian Stok (Stock Adjustment)** di dashboard FE atau impor batch koreksi.
  2. Jika selisih terjadi massal setelah 1-2 minggu, ekspor ulang Excel dari Jubelio lalu jalankan kembali `inventory:import-baseline --commit --zero-missing` (sistem bersifat idempoten).

### Kasus 4: Rencana Darurat (Rollback Plan)
Jika terjadi kendala operasional yang tak terduga:
1. **Stok Cilupbah:** Stok internal cilupbah tidak terhubung ke marketplace (karena `stock_push_enabled = false`), sehingga marketplace 100% aman dan tidak terpengaruh.
2. **Kembalikan ke Shadow Mode:**
   ```bash
   kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker --execute="
   \Modules\Channel\Models\ChannelShop::query()->update(['is_shadow_mode' => true]);
   "
   ```

---

## 5. Checklist Verifikasi Pasca-Migrasi

- [ ] Laporan CSV hasil commit diunduh dan diverifikasi total pcs-nya.
- [ ] Posisi stok di dashboard FE menu **Persediaan > Posisi Stok** sudah menampilkan angka stok per rak.
- [ ] Pesanan baru masuk di menu **Pesanan**, kolom `on_order` bertambah.
- [ ] Pesanan yang diselesaikan via tombol **"Selesaikan Langsung"** berhasil memotong stok `on_hand` di rak terkait dan melepas `on_order`.
- [ ] Log antrean channel menunjukkan `SyncProductToChannelJob skipped` (membuktikan gembok push ke marketplace bekerja 100%).
