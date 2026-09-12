# Order Cutover Console

Halaman internal tersedia di `/_ops/order-cutover/{token}`. Token memakai `ORDER_CUTOVER_CONSOLE_TOKEN`; bila kosong, konfigurasi otomatis memakai `STOCK_CUTOVER_CONSOLE_TOKEN`.

Alur operasional:

1. Upload empat CSV sesuai kategori: gagal pengambilan, stok kosong internal, siap proses, dan menunggu pembayaran.
2. Jalankan dry-run. Sistem membaca timestamp order dari setiap CSV sebagai WIB, mengambil timestamp paling baru sebagai cutoff, dan selalu memakai Gudang Kecil (kode `O`).
3. Download laporan JSON. Apply normal hanya tersedia bila semua order CSV ditemukan, tidak ada duplikat, kandidat belum masuk proses gudang, dan tidak ada relasi dokumen anak. Jika operator mengaktifkan mode partial, hanya kandidat aman yang dihapus dan blocking candidate dilewati.
4. Hentikan sinkronisasi order channel dan proses gudang, centang dua konfirmasi, lalu ketik `APPLY-ORDER-CUTOVER`.

Aturan apply bersifat whitelist-plus-newer: order yang cocok dengan `salesorder_no`/`channel_order_no` (termasuk variasi prefix SP/LZ/TT/TP) dan order dengan `created_at` atau `transaction_date` pada atau setelah cutoff dipertahankan. Order kandidat lain dihapus hanya dalam scope Gudang Kecil. Baris CSV yang memiliki order tetapi tanggal/jam tidak valid atau lokasi bukan Gudang Kecil menjadi blocking issue. Webhook inbox dan master SKU tidak disentuh.

Mode partial (`APPLY_PARTIAL`) bersifat opt-in dan hanya menghapus kandidat lama yang belum masuk proses gudang, belum memiliki `handed_to_warehouse_at`, serta tidak memiliki relasi child. Order yang terdeteksi berisiko dipertahankan dan dicantumkan di laporan.

Queue `order-cutover` pada koneksi `redis-long` berbagi satu worker Horizon dengan
`stock-cutover`. Keduanya sengaja diserialkan karena proses import dapat memakai
memori besar; jangan menjalankan dua cutover console secara bersamaan. Jalankan
migration dan restart Horizon setelah deploy.
