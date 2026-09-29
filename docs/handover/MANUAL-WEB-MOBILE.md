# Manual Operasional Web & Mobile

## 1. Tujuan

Manual ini menjelaskan pekerjaan harian operator. Halaman `Bantuan` di web
menjadi panduan kontekstual di dalam aplikasi; dokumen ini melengkapinya
dengan urutan kerja, pembagian tanggung jawab, dan langkah pemulihan.

## 2. Persiapan awal

1. Pastikan akun memiliki role dan permission yang sesuai.
2. Pastikan gudang, lokasi/bin, SKU, barcode, channel, dan toko sudah dibuat.
3. Pastikan koneksi marketplace pada menu **Integrasi Channel** berstatus aktif.
4. Untuk mobile, pasang aplikasi Android dari distribusi yang disetujui,
   izinkan kamera, lalu login dengan akun warehouse.
5. Jangan membagikan akun bersama. Setiap operator memakai akun sendiri agar
   audit log dapat ditelusuri.

### URL dan batas akses

| Environment | Web | Backend/API | Dokumentasi API |
|---|---|---|---|
| Staging | `https://dev-frontend-app.ultra-fit.id/` | `https://dev-backend-app.ultra-fit.id/` | `https://dev-backend-app.ultra-fit.id/api/documentation` |
| Production | `https://app.ultra-fit.id/` | `https://be-superapp.ultra-fit.id/` | `https://be-superapp.ultra-fit.id/api/documentation` |

Jika halaman masih mengarah ke host lama, laporkan ke maintainer. DNS, Ingress,
dan callback marketplace harus dipindahkan bersama-sama dan diuji.

## 3. Peran utama

| Peran | Pekerjaan utama |
|---|---|
| Owner/Admin | Pengaturan, user/role, channel, monitoring, laporan dan koreksi terotorisasi |
| Purchasing | Supplier, PO, penerimaan pembelian |
| Warehouse | Penerimaan, QC, putaway, transfer dan stok |
| Picker | Picking berdasarkan picklist dan scan barcode |
| Checker/Packer | Verifikasi barang, packing dan pengecekan paket |
| CS/Admin Marketplace | Pesanan, status channel, resi, retur dan pembatalan |

Role yang tersedia mengikuti permission matrix pada instalasi. Tombol yang tidak
terlihat bukan bukti izin telah diberikan; izin tetap divalidasi backend.

## 4. Alur Web utama

### 4.1 Master produk dan SKU

1. Buka **Produk** dan buat/import produk.
2. Pastikan SKU internal, barcode, varian, satuan, dan status aktif benar.
3. Hubungkan SKU internal dengan SKU marketplace pada integrasi channel.
4. Gunakan menu download/upload sesuai template resmi; periksa hasil validasi
   sebelum menyimpan perubahan massal.

### 4.2 Gudang dan lokasi

1. Buat gudang, zona, rak/bin, dan mapping gudang channel bila diperlukan.
2. Pastikan satu lokasi memiliki kapasitas dan status aktif yang benar.
3. Jangan menghapus lokasi yang masih memiliki stok atau transaksi aktif; gunakan
   alur pemindahan/penutupan yang tersedia.

### 4.3 Inbound dan putaway

1. Buat atau pilih PO.
2. Buka **Barang Masuk**, lakukan penerimaan sesuai qty fisik.
3. Jalankan QC: terima, tolak, atau partial.
4. Di mobile, buka tugas putaway, scan barcode SKU dan scan lokasi tujuan.
5. Konfirmasi penempatan. Stok dan ledger berubah setelah transaksi berhasil.

Jika scan gagal, jangan mengulang berkali-kali tanpa membaca pesan. Periksa SKU,
barcode, lokasi, dan status tugas terlebih dahulu.

### 4.4 Pesanan, resi, picking, packing, shipping

1. Pesanan channel masuk melalui webhook/sinkronisasi dan tampil di **Pesanan**.
2. Periksa status marketplace dan status internal sebelum memproses.
3. Pada **Proses Pesanan**, tarik resi atau label melalui modal yang tersedia.
   Status `pending` berarti channel masih memproses; jangan membuat request
   duplikat berulang-ulang.
4. Buat atau buka picklist. Picker memindai SKU di mobile.
5. Checker memvalidasi hasil picking bila alur tersebut digunakan.
6. Packer memindai/memvalidasi barang dan menyelesaikan packing.
7. Masukkan paket ke manifest/shipping sesuai SOP gudang.
8. Cetak label hanya untuk item berstatus siap. Item pending dapat di-retry
   setelah penyebabnya selesai.

Status marketplace dapat berbeda dari status internal karena API channel bersifat
asinkron. Perbedaan sementara harus dilihat di kronologi dan log channel, bukan
langsung diubah manual.

### 4.5 Stok dan sinkronisasi channel

1. Perubahan stok fisik dilakukan melalui inbound, outbound, transfer, adjustment,
   opname, atau retur.
2. Sistem membuat pekerjaan sinkronisasi stok ke channel melalui queue.
3. Pantau **Monitor Stok** untuk status berhasil, pending, skipped, atau failed.
4. Retry hanya item gagal/pending; jangan mengulang seluruh toko jika tidak perlu.
5. Jika stok channel berbeda, simpan bukti nomor SKU, toko, waktu, dan pesan error
   sebelum meminta eskalasi.

### 4.6 Koneksi marketplace dan webhook

Admin harus memastikan toko berstatus **terhubung/aktif** pada menu integrasi.
Hubungkan ulang toko bila token kedaluwarsa atau portal marketplace melaporkan
toko belum terkait dengan partner. Perubahan URL webhook/callback dilakukan di
portal marketplace dan tidak cukup hanya dengan mengganti DNS. Setelah perubahan,
kirim satu event uji, pastikan pesanan masuk satu kali, lalu uji event duplikat.

### 4.7 Retur, pembatalan, dan laporan

- Retur: terima paket, lakukan QC, lalu putaway ke stok aktif atau karantina.
- Pembatalan: ikuti status channel dan guard bisnis; jangan mengurangi/mengembalikan stok secara manual di luar alur.
- Laporan: gunakan filter tanggal WIB, pilih pagination, lalu unduh dari pusat download. Jangan menutup modal sebelum status pekerjaan tercatat.

## 5. Alur Mobile

Mobile digunakan untuk pekerjaan fisik yang membutuhkan scan:

- **Picking**: buka picklist → scan SKU → verifikasi qty → lanjut item berikutnya.
- **Putaway**: buka tugas → scan SKU → scan lokasi → konfirmasi.
- **Receiving**: pilih penerimaan → scan/isi qty → simpan hasil QC.
- **Scan Stock/Opname**: pilih gudang/lokasi → scan → koreksi hanya dengan izin.
- **Transfer**: scan item transfer → konfirmasi asal/tujuan sesuai status.

Jika jaringan terputus, jangan menganggap scan sudah tersimpan. Tunggu status
berhasil atau lakukan verifikasi ulang dari daftar tugas.

## 6. Penanganan masalah umum

| Gejala | Tindakan operator |
|---|---|
| Pesanan belum muncul | Cek filter tanggal/channel, status webhook, lalu minta admin menjalankan recovery; jangan membuat order duplikat |
| Status channel belum berubah | Tunggu polling sesuai status pending; simpan nomor order dan waktu request |
| Resi belum tersedia | Retry item pending, bukan item Ready; periksa batas/rate limit channel |
| Label gagal | Catat order, channel, pesan error; retry batch yang gagal setelah batch aktif selesai |
| Stok berbeda | Cek kronologi, ledger, outbox sync, dan status channel; jangan langsung adjustment |
| Scan ditolak | Pastikan SKU/lokasi/tugas benar dan akun memiliki permission |
| Laporan lambat | Perkecil rentang/filter, gunakan pusat download, dan eskalasi bila queue tua |

## 7. Aturan handover kepada operator

- Semua tindakan koreksi harus memakai akun pribadi.
- Jangan membagikan token marketplace, APP_KEY, password database, atau private key.
- Catat order/SKU/toko/waktu/pesan error saat eskalasi.
- Gunakan environment staging untuk latihan.
- Target performa, rate limit, dan waktu terbit AWB mengikuti kemampuan API resmi marketplace; manual ini tidak menjanjikan semua proses selesai dalam hitungan detik.
