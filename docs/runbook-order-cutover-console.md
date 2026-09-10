# Order Cutover Console

Halaman internal tersedia di `/_ops/order-cutover/{token}`. Token memakai `ORDER_CUTOVER_CONSOLE_TOKEN`; bila kosong, konfigurasi otomatis memakai `STOCK_CUTOVER_CONSOLE_TOKEN`.

Alur operasional:

1. Upload empat CSV sesuai kategori: gagal pengambilan, stok kosong internal, siap proses, dan menunggu pembayaran.
2. Isi cutoff dalam WIB dan kode gudang, lalu jalankan dry-run.
3. Download laporan JSON. Apply hanya tersedia bila semua order CSV ditemukan, tidak ada duplikat, kandidat belum masuk proses gudang, dan tidak ada relasi dokumen anak.
4. Hentikan sinkronisasi order channel dan proses gudang, centang dua konfirmasi, lalu ketik `APPLY-ORDER-CUTOVER`.

Aturan apply bersifat whitelist-plus-newer: order yang cocok dengan `salesorder_no`/`channel_order_no` (termasuk variasi prefix SP/LZ/TT/TP) dan order dengan `created_at` atau `transaction_date` setelah cutoff dipertahankan. Order kandidat lain dihapus hanya dalam scope kode gudang yang dipilih. Webhook inbox dan master SKU tidak disentuh.

Queue default adalah `order-cutover` pada koneksi `redis-long`, dengan supervisor Horizon khusus satu worker. Jalankan migration dan restart Horizon setelah deploy.
