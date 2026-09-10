# ADR: Jalur Kritis Sinkronisasi Stok ke Channel

Status: Accepted  
Tanggal: 2026-09-10

## Keputusan

Perubahan stok real-time diproses melalui antrean 'stock-critical' pada koneksi Redis
utama, sedangkan resinkronisasi manual/massal diproses melalui 'stock-default'.
Supervisor stok membaca 'stock-critical' lebih dahulu sehingga pekerjaan stok akibat
transaksi selalu didahulukan dari backfill.

Job sinkronisasi produk menggunakan deduplikasi sampai mulai diproses. Beberapa event
stok dengan aksi yang sama yang datang beruntun untuk produk dan toko yang sama
digabung, lalu jumlah stok terbaru dihitung ketika job benar-benar berjalan. Aksi
sync_stock dan sync_price_stock sengaja memiliki identitas berbeda agar perubahan
harga tidak pernah hilang. Lock produk/toko tetap dipakai untuk mencegah dua
permintaan marketplace yang saling bertabrakan.

Fan-out komponen bundle dideduplikasi dalam satu eksekusi, dan mapping varian untuk
toko terkait diambil secara eager-load. Adapter Shopee, TikTok, Lazada, dan
WooCommerce memakai mapping tersebut sehingga tidak membuat query baru untuk setiap
varian.

Circuit breaker dicatat per channel dan toko, bukan global untuk seluruh channel.
Kegagalan satu toko tidak boleh menghentikan sinkronisasi toko lain, dan penghitung
kegagalan direset setelah sinkronisasi berhasil.

## Alasan

Stok adalah data operasional yang berdampak langsung pada overselling. Sebelumnya
perubahan stok dapat ikut tertahan oleh antrean katalog yang lambat, atau membuat
job duplikat ketika satu varian dipakai oleh beberapa bundle. Kondisi tersebut
menambah beban Redis, database, PHP worker, dan API marketplace tanpa menambah
ketepatan hasil.

## Invarian bisnis

- Rumus sumber stok dan aturan on_hand, on_order, buffer, bundle, serta toko
  tidak diubah.
- Job stok tidak mengubah data pesanan atau histori stok.
- Toko dengan stock_push_enabled=false, listing nonaktif, atau semua mapping
  variannya nonaktif tetap dilewati.
- Listing yang belum memiliki external product ID dilewati oleh jalur stok;
  event tersebut tidak boleh berubah menjadi upload katalog.
- Kegagalan channel dicatat sebagai gagal dan dapat dicoba kembali sesuai retry
  policy; tidak dianggap sukses secara diam-diam.
- Resinkronisasi massal tidak boleh menahan event stok real-time.

## Operasional

1. Pantau panjang stock-critical, waktu tunggu, jumlah retry/failed, dan status
   mapping setelah rilis.
2. Default supervisor stok memakai satu worker agar beban CPU VPS tetap terkendali. Naikkan
   paralelisme hanya berdasarkan metrik dan hasil load test.
3. Jika marketplace timeout, circuit breaker membatasi retry per toko tanpa
   memutus toko lain.
4. Perubahan env queue harus diikuti reload Horizon agar konfigurasi worker aktif
   sesuai image terbaru.
