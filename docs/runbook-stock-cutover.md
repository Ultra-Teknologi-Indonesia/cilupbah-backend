# runbook reset stok dan cutover order

Dokumen ini adalah prosedur operasional untuk memindahkan angka stok dari Jubelio ke Cilupbah dengan cutoff tertentu. Semua command selain `preflight`, audit, dan `verify` berada dalam mode dry-run jika `--apply` tidak diberikan.

## aturan utama

- gunakan kode gudang eksplisit, jangan menggunakan `ALL`, agar gudang transit atau system location tidak ikut terhapus;
- file manifest SKU dari tim gudang wajib berisi seluruh SKU, termasuk SKU dengan qty 0;
- file baseline memakai `qty aktual` sebagai `on_hand`, sedangkan `on_order` selalu dibangun ulang dari order aktif;
- timestamp cutoff ditulis dalam WIB, contoh `2026-09-03 18:00:00`;
- order dengan status `shipped`, `completed`, `delivered`, atau `cancelled` dan `updated_at` sebelum cutoff dihapus;
- order aktif lain tetap dipertahankan, histori picking/packing/shipping dibersihkan, lalu order dinormalisasi kembali ke `pending`, sedangkan order yang sudah dibatalkan tidak diaktifkan kembali;
- invoice dan payment order terminal hanya dihapus jika `--purge-finance` diberikan;
- SKU, product variant, gudang, user, rak, dan `sku_rack_assignments` tidak menjadi target penghapusan;
- webhook tetap ditampung di `channel_webhook_inbox` saat pause, lalu diproses batch setelah resume;
- history transfer antar gudang, inbound/putaway, picking, packing, shipping/manifest, backfill stok, replenishment request, opname, adjustment, dan movement reversal ikut dibersihkan untuk gudang yang dipilih;
- push stok harus tetap mati sampai verifikasi selesai dan Jubelio tidak lagi menjadi writer stok.

## urutan command

```bash
php artisan migrate --force

php artisan cutover:preflight \
  --cutoff="2026-09-03 18:00:00" \
  --locations=WH-PUSAT,WH-KECIL \
  --sku-manifest=/secure/cutover/sku-manifest.xlsx \
  --stock-file=/secure/cutover/pusat.xlsx \
  --stock-file=/secure/cutover/kecil.xlsx
```

Simpan `run_id` dari output, lalu jalankan semua audit berikut tanpa `--apply`:

```bash
php artisan cutover:sku-audit /secure/cutover/sku-manifest.xlsx --run-id=<run_id>
php artisan cutover:stock-audit /secure/cutover/pusat.xlsx --location=WH-PUSAT --run-id=<run_id>
php artisan cutover:stock-audit /secure/cutover/kecil.xlsx --location=WH-KECIL --run-id=<run_id>
php artisan cutover:order-audit --run-id=<run_id>
php artisan cutover:reset --run-id=<run_id>
php artisan cutover:pause --run-id=<run_id>
php artisan cutover:rebuild-reservation --run-id=<run_id>
php artisan cutover:resume --run-id=<run_id>
php artisan cutover:replay-orders --run-id=<run_id> --limit=50
```

Jika seluruh audit tidak memiliki blocking issue, hentikan channel dan apply reset:

```bash
php artisan cutover:pause --run-id=<run_id> --apply --confirm=PAUSE-CUTOVER
php artisan cutover:reset --run-id=<run_id> --purge-finance --apply --confirm=RESET-STOCK-DATA
```

Import dilakukan satu kali per gudang:

```bash
php artisan cutover:import-stock /secure/cutover/pusat.xlsx \
  --location=WH-PUSAT --run-id=<run_id> --zero-missing \
  --apply --confirm=IMPORT-STOCK

php artisan cutover:import-stock /secure/cutover/kecil.xlsx \
  --location=WH-KECIL --run-id=<run_id> --zero-missing \
  --apply --confirm=IMPORT-STOCK
```

Bangun ulang reservation dan verifikasi:

```bash
php artisan cutover:rebuild-reservation --run-id=<run_id> --apply --confirm=REBUILD-RESERVATION
php artisan cutover:verify --run-id=<run_id>
```

Setelah verify sukses, buka order intake. Push stok tetap mati:

```bash
php artisan cutover:resume --run-id=<run_id> --apply --confirm=RESUME-CUTOVER
php artisan cutover:replay-orders --run-id=<run_id> --limit=50 --apply --confirm=REPLAY-ORDERS
```

Jalankan replay berulang sampai batch dry-run berikutnya menunjukkan 0 record tertahan, kemudian jalankan `cutover:verify` lagi setelah replay selesai. Setelah itu, matikan sync stok Jubelio dan lakukan stock handover Cilupbah per shop menggunakan `channel:stock-handover --dry-run`, lalu apply setelah precondition terpenuhi.

## validasi pasca cutover

Simpan output setiap command beserta `run_id`. Cocokkan total `on_hand` per SKU dan gudang dengan export Jubelio, pastikan tidak ada terminal order sebelum cutoff, tidak ada dokumen transfer/inbound/outbound lama, SKU dan alokasi rak tetap ada, `on_order` hanya berasal dari order aktif, serta tidak ada dua sistem yang mengaktifkan push stok secara bersamaan.
