# PRD Brief — Temporary Locator per Zone

## 1. Ringkasan

### Tujuan

Menyediakan alur terkontrol untuk barang **cancel** dan **overpick** yang sudah keluar dari rak picking tetapi belum boleh langsung dikembalikan ke rak asal.

Barang tersebut ditempatkan lebih dulu pada **Temporary Locator** sesuai zona rak asal, diverifikasi oleh Leader/PIC, lalu dikembalikan melalui proses transfer dan putaway yang tercatat.

### Masalah saat ini

Tanpa alur temporary locator yang khusus:

- barang bermasalah berisiko diletakkan di lokasi yang tidak tercatat;
- picker dapat mencari barang yang sebenarnya masih berada di area sementara;
- stok dapat terlihat tersedia padahal belum siap dijual;
- proses pengembalian bergantung pada pencatatan manual;
- sulit menelusuri siapa yang memindahkan barang dan mengapa.

### Hasil yang diharapkan

```text
Scan barang bermasalah
        ↓
Sistem menentukan TEMP berdasarkan zona rak asal
        ↓
Barang tidak sellable sementara
        ↓
Leader/PIC verifikasi SKU dan jumlah
        ↓
Admin assign transfer dan putaway
        ↓
Staff scan barang + rak tujuan
        ↓
Barang kembali ke rak picking dan sellable
```

## 2. Pengguna dan tanggung jawab

| Peran | Tanggung jawab |
|---|---|
| Checker/Manifest | Scan SKU atau order yang cancel/overpick dan mengonfirmasi jumlah bermasalah |
| Leader/PIC | Memeriksa SKU, jumlah fisik, alasan, dan menyetujui atau menolak transaksi |
| Admin Gudang | Mengatur mapping zona, memilih/menyetujui rak tujuan, dan membuat assignment putaway |
| Staff Putaway | Mengambil barang dari TEMP dan scan SKU serta locator tujuan |
| Supervisor/Admin | Melihat laporan, audit trail, discrepancy, dan melakukan koreksi yang berwenang |

## 3. Ruang lingkup

### Termasuk

- Mapping rentang home locator ke zona dan temporary locator.
- Scan barang cancel atau overpick dari Checker/Manifest.
- Penentuan TEMP otomatis berdasarkan home locator.
- Stok TEMP berstatus non-sellable.
- Verifikasi SKU, jumlah, alasan, dan bukti discrepancy.
- Transfer internal dalam gudang kecil dari TEMP ke lokasi tujuan.
- Assignment dan penyelesaian putaway dengan scan.
- Audit trail, status transaksi, filter, dan laporan.
- Pencegahan double scan dan double movement.
- Pengecualian stok TEMP dari `available` dan push stok channel.

### Tidak termasuk dalam brief ini

- Perubahan alur order normal yang tidak cancel atau overpick.
- Perubahan API marketplace selain memastikan stok TEMP tidak dikirim.
- Migrasi ulang seluruh histori lama tanpa transaksi TEMP.
- Integrasi perangkat scanner khusus di luar kemampuan scan yang sudah ada.
- Penentuan kebijakan akuntansi/finansial atas barang rusak atau hilang.

## 4. Konsep bisnis

### Home locator dan zona

Admin membuat aturan seperti:

| Home locator | Zona | Temporary locator |
|---|---|---|
| A1–A8 | Zone A | TEMP-A |
| A9–A16 | Zone B | TEMP-B |
| B1–B8 | Zone C | TEMP-C |
| B9–B16 | Zone D | TEMP-D |

Mapping dapat disesuaikan oleh Admin. Sistem tidak boleh menggunakan TEMP umum sebagai fallback jika home locator atau mapping belum tersedia; transaksi harus dihentikan untuk ditangani manual.

### Arti stok di TEMP

- `On hand` tetap tercatat karena barang masih berada di gudang.
- `Available` menjadi nol selama barang berada di TEMP.
- Barang TEMP tidak boleh dipilih oleh picker dan tidak boleh dikirim ke Shopee, TikTok, Lazada, WooCommerce, atau channel lain.
- Setelah putaway selesai ke locator sellable, `available` dapat kembali dihitung.

## 5. Rencana flow di website

### 5.1 Entry dari Checker/Manifest

Pada halaman Checker/Manifest, tambahkan aksi **“Barang Bermasalah”** atau gunakan hasil scan yang terdeteksi sebagai cancel/overpick.

Form yang ditampilkan:

- nomor order/picklist (jika tersedia);
- SKU/varian fisik;
- jumlah sistem;
- jumlah bermasalah yang akan ditahan;
- alasan: `CANCEL`, `OVERPICK`, atau alasan lain yang diizinkan;
- home locator;
- zona dan TEMP yang ditentukan sistem;
- catatan/foto/bukti (opsional sesuai kebutuhan operasional).

Checker mengonfirmasi **“Masukkan ke TEMP”**. Sistem membuat satu transaksi issue dan menampilkan status **Menunggu Verifikasi**.

### 5.2 Daftar Temporary Locator

Menu yang disarankan:

```text
Persediaan → Temporary Locator
```

Daftar memiliki tab/status:

- Menunggu Verifikasi;
- Sudah Diverifikasi;
- Putaway Ditugaskan;
- Putaway Selesai;
- Discrepancy/Ditolak.

Filter minimal:

- tanggal;
- zona/TEMP;
- SKU;
- alasan;
- status;
- checker, Leader/PIC, atau staff.

### 5.3 Verifikasi Leader/PIC

Detail transaksi menampilkan:

- SKU dan nama produk;
- jumlah sistem dan jumlah fisik;
- jumlah yang masuk TEMP;
- rak asal dan TEMP;
- order/picklist terkait;
- alasan;
- waktu dan pengguna yang melakukan scan;
- riwayat perubahan.

Pilihan Leader/PIC:

- **Verifikasi** → transaksi lanjut ke Admin.
- **Tolak/Discrepancy** → transaksi ditahan, wajib mengisi alasan dan selisih.
- **Batalkan transaksi** → hanya jika belum ada movement fisik atau sesuai kewenangan.

### 5.4 Assign transfer dan putaway

Untuk transaksi berstatus verified, Admin memilih:

- gudang asal dan tujuan (keduanya Gudang Kecil untuk flow ini);
- TEMP asal;
- locator tujuan, default-nya home locator yang tersimpan saat scan;
- staff putaway;
- catatan.

Perpindahan ini adalah **transfer lokasi**, bukan penambahan stok baru.

Status menjadi **Putaway Ditugaskan**.

### 5.5 Penyelesaian putaway

Staff membuka tugas, kemudian:

1. Scan transaksi atau TEMP.
2. Scan SKU/barang.
3. Scan locator tujuan.
4. Sistem mencocokkan SKU, jumlah, TEMP, dan locator.
5. Staff menyelesaikan putaway.

Jika cocok, status menjadi **Putaway Selesai**. Sistem mencatat movement TEMP → locator tujuan dan mengembalikan stok ke status sellable.

Jika tidak cocok, sistem menolak scan dan memberi alasan yang mudah dipahami.

## 6. Status dan transisi

| Status | Arti | Aksi berikutnya |
|---|---|---|
| `TEMP_PENDING` | Hasil scan sudah dicatat, barang ditahan di TEMP | Menunggu verifikasi |
| `WAITING_VERIFICATION` | Menunggu pemeriksaan Leader/PIC | Verifikasi atau discrepancy |
| `VERIFIED` | SKU, jumlah, dan alasan sudah disetujui | Admin assign putaway |
| `PUTAWAY_ASSIGNED` | Tugas putaway sudah diberikan kepada staff | Staff melakukan scan |
| `PUTAWAY_DONE` | Barang sudah masuk locator tujuan | Stok kembali sellable |
| `DISCREPANCY` | Jumlah/SKU fisik tidak sesuai atau transaksi ditolak | Penyelesaian manual oleh pihak berwenang |
| `CANCELLED` | Transaksi dihentikan sebelum selesai | Tidak ada movement lanjutan |

Transisi mundur tidak diperbolehkan setelah movement putaway selesai, kecuali melalui transaksi koreksi/transfer baru yang memiliki audit trail.

## 7. Business logic kondisional

1. **Jika alasan = CANCEL**
   - Barang yang sudah keluar dari rak ditahan di TEMP.
   - Barang tidak boleh masuk ke order baru sampai putaway selesai.

2. **Jika alasan = OVERPICK**
   - Hanya jumlah surplus yang masuk TEMP.
   - Jumlah yang memang dibutuhkan order tetap mengikuti flow order normal.

3. **Jika home locator ditemukan dan mapping zona aktif**
   - Sistem menentukan TEMP secara otomatis.

4. **Jika home locator tidak ditemukan atau mapping tidak aktif**
   - Sistem menolak proses otomatis dan meminta penanganan manual.

5. **Jika jumlah fisik sama dengan jumlah sistem**
   - Leader/PIC dapat memverifikasi.

6. **Jika jumlah fisik berbeda**
   - Status menjadi `DISCREPANCY`.
   - Putaway tidak boleh diassign sampai discrepancy diselesaikan.

7. **Jika transaksi sudah pernah discan atau selesai**
   - Scan ulang tidak boleh membuat movement atau pengurangan stok kedua.
   - Sistem menampilkan status transaksi sebelumnya.

8. **Jika SKU berupa bundle**
   - Aturan scan harus menggunakan SKU fisik/komponen yang benar-benar dipindahkan.
   - Sistem tidak boleh mencampur identitas listing marketplace dengan SKU fisik.

9. **Jika locator tujuan penuh/tidak aktif**
   - Admin wajib memilih locator sellable lain yang diizinkan.
   - Sistem tidak boleh menaruh barang ke locator yang dikunci atau non-sellable.

10. **Jika proses putaway dibatalkan di tengah jalan**
    - Barang tetap berstatus non-sellable di TEMP.
    - Tidak ada pengembalian `available` sebelum putaway benar-benar selesai.

## 8. Validasi wajib

| Validasi | Hasil jika gagal |
|---|---|
| SKU/varian terdaftar | Scan ditolak, tampilkan SKU tidak ditemukan |
| Jumlah lebih besar dari jumlah yang bermasalah | Scan ditolak |
| Home locator tersedia | Minta penanganan manual jika tidak tersedia |
| Mapping zona aktif | Transaksi tidak dibuat tanpa mapping |
| TEMP aktif dan non-sellable | Transaksi ditolak jika TEMP tidak valid |
| SKU sesuai transaksi | Scan ditolak jika berbeda |
| Locator tujuan aktif dan sellable | Scan ditolak jika tidak valid |
| Jumlah putaway sama dengan jumlah verified | Tidak boleh kurang/lebih tanpa discrepancy |
| User memiliki hak akses | Aksi ditolak jika peran tidak sesuai |
| Status mengizinkan aksi | Aksi lama/duplikat ditolak |
| Satu movement per tahap | Cegah double decrement/double increment |

## 9. Hak akses

| Aksi | Checker | Leader/PIC | Admin | Staff Putaway |
|---|:---:|:---:|:---:|:---:|
| Membuat transaksi TEMP | Ya | Ya | Ya | Tidak |
| Verifikasi/reject | Tidak | Ya | Ya | Tidak |
| Mengatur mapping zona | Tidak | Tidak | Ya | Tidak |
| Assign putaway | Tidak | Opsional | Ya | Tidak |
| Menyelesaikan putaway | Tidak | Tidak | Opsional | Ya |
| Melihat audit | Terbatas | Ya | Ya | Terbatas |

## 10. Dampak terhadap sistem yang sudah ada

- Alur order normal, picking normal, packing, dan shipping tidak berubah.
- Checker/Manifest mendapatkan jalur tambahan untuk barang cancel/overpick.
- Inventory perlu mengenali TEMP sebagai lokasi non-sellable.
- Available stock harus mengecualikan quantity di TEMP.
- Channel stock push harus hanya membaca stok sellable.
- Modul transfer/putaway yang sudah ada sebaiknya digunakan kembali agar tidak membuat pencatatan stok ganda.
- Laporan stok perlu membedakan stok rak picking, TEMP, dan lokasi non-sellable lainnya.
- Audit trail perlu menyimpan actor, waktu, alasan, jumlah sebelum/sesudah, sumber, dan tujuan.

## 11. Kebutuhan nonfungsional

- Setiap scan harus idempotent dan aman jika tombol ditekan dua kali.
- Perubahan stok harus atomik: movement dan perubahan status berhasil bersama atau batal bersama.
- Tidak boleh ada query besar yang memuat seluruh histori TEMP sekaligus; daftar harus berpaginasi.
- Semua aksi penting harus tercatat untuk audit.
- Kesalahan operasional harus tampil dalam bahasa sederhana, bukan stack trace.
- Kegagalan API atau worker tidak boleh menyebabkan stok terhitung dua kali.
- Fitur dapat diaktifkan bertahap melalui feature flag atau konfigurasi gudang.

## 12. Acceptance criteria

1. Checker dapat scan barang cancel/overpick dan sistem menentukan TEMP sesuai mapping.
2. Barang yang berada di TEMP tidak muncul sebagai `available` dan tidak dikirim ke channel marketplace.
3. Leader/PIC dapat menyetujui atau menolak transaksi dengan alasan.
4. Transaksi discrepancy tidak dapat langsung diproses putaway.
5. Admin dapat assign locator tujuan dan staff putaway.
6. Staff hanya dapat menyelesaikan putaway jika SKU, jumlah, dan locator benar.
7. Setelah putaway selesai, movement TEMP → locator tujuan tercatat dan stok kembali sellable.
8. Scan atau klik ulang tidak menghasilkan movement kedua.
9. Sistem menyimpan audit trail lengkap dari scan sampai selesai.
10. Alur order normal tetap berjalan seperti sebelumnya.
11. Semua kasus tanpa mapping, SKU salah, jumlah salah, TEMP nonaktif, dan locator tujuan tidak valid ditolak dengan pesan yang jelas.

## 13. Rencana implementasi bertahap

### Tahap 1 — Konfigurasi dan model bisnis

- Finalisasi daftar zona/TEMP dan aturan mapping.
- Finalisasi alasan cancel/overpick dan kebijakan discrepancy.
- Pastikan TEMP ditandai non-sellable.

### Tahap 2 — Backend dan stok

- Membuat transaksi issue TEMP dan state transition.
- Menghubungkan inventory movement dengan transfer/putaway existing.
- Menambahkan idempotency, permission, dan audit trail.
- Memastikan channel stock resolver mengecualikan TEMP.

### Tahap 3 — Website

- Aksi scan dari Checker/Manifest.
- Daftar dan detail Temporary Locator.
- Verifikasi Leader/PIC.
- Assign dan penyelesaian putaway.

### Tahap 4 — QA dan pilot gudang

- Uji satu zona terlebih dahulu.
- Uji cancel, overpick, discrepancy, scan ulang, bundle, dan locator tidak aktif.
- Pantau stok dan channel push sebelum mengaktifkan seluruh zona.

## 14. Keputusan yang perlu dikonfirmasi client

- Apakah TEMP dibuat per zona seperti `TEMP-A`, `TEMP-B`, dan seterusnya, atau ada batas jumlah TEMP tertentu?
- Apakah barang rusak/hilang memakai flow TEMP yang sama atau flow terpisah?
- Apakah Leader/PIC boleh mengubah jumlah, atau hanya boleh menerima/menolak?
- Apakah locator tujuan selalu home locator atau boleh dipilih dari locator sellable lain?
- Apakah foto/bukti wajib untuk cancel dan overpick?
- Apakah transaksi yang sudah `PUTAWAY_DONE` boleh dikoreksi melalui role Supervisor?
- Apakah scope awal hanya Gudang Kecil, atau juga Gudang Pusat?

## 15. Ringkasan keputusan produk

Fitur ini adalah **jalur pengendalian stok bermasalah**, bukan perubahan pada alur penjualan normal. Prinsip utamanya:

> Barang yang belum kembali ke rak tidak boleh dianggap siap dijual.

Sistem harus selalu menjaga tiga hal: barang dapat ditemukan, jumlahnya dapat diverifikasi, dan setiap perpindahan memiliki riwayat yang jelas.

## 16. Rekomendasi keputusan untuk penggunaan paling mudah

Bagian ini menetapkan pilihan awal yang paling sederhana untuk operator, tanpa mengurangi kontrol stok.

### 16.1 Mulai dari Gudang Kecil

Fitur pertama kali diaktifkan hanya untuk **Gudang Kecil**, karena kasus operasional yang diminta berasal dari lokasi tersebut. Gudang Pusat dapat ditambahkan setelah flow stabil.

### 16.2 Satu TEMP untuk setiap zona

Gunakan satu temporary locator per zona terlebih dahulu:

```text
Zone A → TEMP-A
Zone B → TEMP-B
Zone C → TEMP-C
Zone D → TEMP-D
```

Jika kapasitas fisik belum cukup, Admin dapat menambah TEMP kedua di zona yang sama pada fase berikutnya. Operator tidak perlu memilih TEMP secara manual; sistem memilihkannya.

### 16.3 Mapping menggunakan pola locator

Admin cukup membuat aturan pola, misalnya `O-A1-*` atau rentang locator, lalu sistem menampilkan preview SKU/rak yang terkena aturan tersebut. Jika sebuah locator belum memiliki mapping, transaksi tidak dibuat otomatis.

### 16.4 Hanya dua alasan utama pada tahap awal

Form Checker cukup menampilkan:

- `Cancel`;
- `Overpick`.

Pilihan `Lainnya` hanya boleh digunakan Admin/Supervisor dan wajib memiliki catatan.

### 16.5 Satu proses scan untuk Checker

Checker cukup:

1. Scan order atau SKU.
2. Pilih alasan.
3. Masukkan jumlah bermasalah.
4. Tekan **Masukkan ke TEMP**.

Home locator, zona, dan TEMP diisi otomatis oleh sistem.

### 16.6 Aturan jumlah yang mudah dipahami

- Cancel: jumlah yang benar-benar sudah keluar dari rak ditahan di TEMP.
- Overpick: hanya jumlah kelebihan yang ditahan di TEMP.
- Checker tidak boleh mengubah jumlah sistem tanpa verifikasi Leader/PIC.
- Jika jumlah fisik berbeda, transaksi masuk `DISCREPANCY` dan berhenti sementara.

### 16.7 Verifikasi satu layar

Leader/PIC cukup melihat jumlah sistem, jumlah fisik, SKU, rak asal, TEMP, dan alasan dalam satu halaman. Tombol utama hanya:

- **Setujui**;
- **Tolak/Discrepancy**.

### 16.8 Rak tujuan otomatis

Setelah disetujui, sistem menyarankan home locator asal sebagai rak tujuan. Admin hanya memilih rak lain jika rak asal penuh, tidak aktif, atau memang ada instruksi khusus.

### 16.9 Putaway wajib scan dua hal

Staff putaway hanya perlu scan:

1. SKU/barang;
2. locator tujuan.

Sistem otomatis memeriksa transaksi, jumlah, dan TEMP asal. Tidak perlu mengetik kode secara manual.

### 16.10 Status yang ditampilkan operator

Gunakan lima status utama yang mudah dipahami:

```text
Menunggu Verifikasi
Sudah Diverifikasi
Putaway Ditugaskan
Putaway Selesai
Discrepancy
```

Nama teknis status dapat tetap digunakan di backend, tetapi website menampilkan bahasa operasional di atas.

### 16.11 Tidak mengubah alur order normal

Barang yang belum benar-benar keluar dari rak tetap mengikuti proses order biasa. Jalur TEMP hanya aktif untuk barang yang sudah dipicking/overpick dan secara fisik perlu ditahan.

### 16.12 Pilot sebelum diaktifkan penuh

Aktifkan lebih dulu untuk satu zona dan satu tim. Setelah tidak ada double movement, salah locator, atau stok TEMP ikut terjual, barulah mapping zona lain diaktifkan.

Dengan keputusan ini, operator hanya berinteraksi dengan empat layar utama: scan Checker, daftar TEMP, verifikasi Leader/PIC, dan tugas putaway Staff. Keputusan lokasi dan penghitungan stok sebisa mungkin dilakukan otomatis oleh sistem.
