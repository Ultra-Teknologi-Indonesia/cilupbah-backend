# Panduan stok dan kronologi

Dokumen ini menjelaskan cara sistem menghitung dan mencatat stok dari seluruh proses operasional. Tujuannya agar tim gudang, operasional, dan admin channel membaca angka yang sama dengan cara yang sama.

Dokumen ini berlaku untuk alur sistem yang aktif saat ini. Baris berpenanda `system:backfill` adalah data migrasi lama; baris tersebut dipisahkan agar tidak dianggap sebagai aktivitas scan yang dilakukan di sistem ini.

## Aturan 30 detik untuk staf gudang

Tidak perlu menghafal istilah sistem. Pegang empat aturan ini:

1. **Pesanan masuk bukan berarti barang keluar dari rak.** Barang baru dicadangkan, jadi stok jual berkurang tetapi barang fisik masih ada di rak.
2. **Barang keluar dari rak saat finish pick.** Di titik ini faktur dan kronologi stok dibuat.
3. **Barang yang sudah diambil kurir tidak boleh langsung masuk stok lagi.** Harus kembali lewat proses retur, terima paket, lalu putaway.
4. **Barang baru boleh dijual jika sudah berada di rak final.** Barang di inbound, temporary, dan transit belum boleh dijual.

Jika ragu, tanyakan satu hal: **barang fisiknya sekarang ada di mana?** Jawaban itu menentukan proses yang benar.

## Ringkasan paling singkat

Ada tiga angka utama untuk setiap SKU dan gudang.

| Angka | Arti sederhana | Rumus |
| --- | --- | --- |
| Stok fisik / on hand | Barang yang benar-benar ada di rak final dan siap dipilih picker. | Jumlah stok di semua rak final. |
| Stok dipesan / on order | Barang di rak yang sudah dijanjikan ke pesanan aktif, tetapi belum selesai di-pick. | Jumlah cadangan semua pesanan aktif. |
| Stok tersedia / available | Barang yang masih boleh dijual ke channel. | `stok fisik - stok dipesan` |

Contoh: di rak ada 10 unit dan ada 3 unit untuk pesanan aktif. Stok fisik tetap 10, stok dipesan 3, sehingga stok tersedia untuk dijual adalah 7.

Stok di bin inbound, area penerimaan, area temporary, atau gudang transit **bukan** stok tersedia untuk dijual. Barang tersebut baru dapat dijual setelah masuk ke rak final melalui proses putaway/penempatan.

## Istilah yang perlu dibedakan

| Istilah | Artinya | Apakah boleh dijual? |
| --- | --- | --- |
| Rak final | Rak shelving/picking tujuan yang digunakan picker. | Ya, setelah dikurangi stok dipesan. |
| Bin inbound | Area barang baru diterima dan masih menunggu penempatan. | Tidak. |
| Temporary zone | Area penampungan barang cancel, overpick, atau barang yang belum lolos proses pengembalian. | Tidak. |
| Transit | Barang sedang berpindah antar gudang. | Tidak dari gudang asal maupun tujuan, sampai diterima dan ditempatkan. |
| On hand | Total barang pada rak final dalam gudang yang dipilih. | Belum tentu seluruhnya bisa dijual karena bisa ada on order. |
| On order | Cadangan untuk pesanan aktif. | Tidak, sudah dialokasikan untuk pesanan tersebut. |
| Available | Angka yang boleh dikirim ke channel atau dipakai untuk pesanan baru. | Ya. |

## Rumus perhitungan yang dipakai sistem

Untuk satu SKU di satu gudang:

```text
stok fisik (on hand) = jumlah stok pada rak final
stok dipesan (on order) = jumlah cadangan pesanan aktif
stok tersedia (available) = stok fisik - stok dipesan
```

Sistem juga menyimpan angka pendukung berikut agar operasional dapat menelusuri posisi barang.

| Angka pendukung | Cara membacanya |
| --- | --- |
| Menunggu penempatan | Barang sudah diterima secara fisik di bin inbound, tetapi belum masuk rak final. |
| Transit | Barang sudah keluar dari gudang asal untuk transfer, tetapi belum selesai diterima di gudang tujuan. |
| Stok fisik total | Rak final + bin inbound. Ini berguna untuk audit fisik, tetapi tidak sama dengan stok tersedia. |
| Legacy/unassigned | Catatan lama yang belum memiliki rak. Ini bukan dasar stok jual dan harus dibereskan melalui proses migrasi/cutover. |

### Kenapa `available` bisa minus?

Dalam operasi normal, sistem menjaga agar pemesanan dan pemotongan fisik tidak melampaui stok rak. Jika stok tidak cukup, proses pick/faktur dihentikan agar stok fisik tidak menjadi negatif.

Angka `available` dapat sementara negatif saat cutover: stok awal telah di-reset ke 0, sinkronisasi pesanan tetap berjalan, lalu pesanan aktif langsung menjadi cadangan sebelum angka stok awal selesai diimpor. Ini adalah sinyal bahwa angka stok awal belum masuk, bukan izin untuk mengambil barang yang tidak ada di rak.

## Prinsip kapan stok berkurang

Prinsip utama yang digunakan tim gudang adalah:

> stok fisik berkurang ketika proses pengambilan selesai (`finish pick`) dan faktur berhasil dibuat dari hasil scan rak.

Saat pesanan baru masuk, sistem hanya mengunci stok jual. Saat picker baru mulai scan, sistem merekam progres kerja dan rak asal. Pemotongan fisik yang menjadi bukti barang telah keluar dari rak dilakukan ketika seluruh item pesanan selesai di-pick dan faktur dibuat.

Dengan aturan ini, satu barang tidak terpotong dua kali antara proses picking, packing, manifest, dan update channel.

> status di channel bukan bukti perpindahan barang fisik. Yang menjadi bukti stok adalah scan, dokumen, qty, dan rak yang tercatat.

## Siklus pesanan normal

| Tahap | Yang terjadi di lapangan | On hand | On order | Available | Kronologi |
| --- | --- | ---: | ---: | ---: | --- |
| Pesanan belum dibayar | Order hanya tercatat. | Tetap | Tetap | Tetap | Tidak ada mutasi stok. |
| Pesanan dibayar / masuk proses | Sistem mencadangkan stok sesuai SKU dan gudang pesanan. | Tetap | Naik | Turun | `Pesanan` / `ORDER_RESERVE` pada tampilan lengkap. |
| Picker mulai scan | Sistem mencatat rak asal dan qty yang diambil. | Tetap | Tetap | Tetap | Progres picking, belum merupakan pemotongan fisik final. |
| Finish pick | Seluruh item berhasil dipindai. Sistem membuat faktur dan memotong stok dari rak yang benar-benar dipindai. | Turun | Lepas menjadi 0 | Secara total tetap setara dengan sebelum finish pick | `Faktur` dengan qty negatif pada kronologi bersih. |
| Packing / manifest | Barang dikemas dan diserahkan ke proses pengiriman. | Tetap | 0 | Tetap | Tidak ada pemotongan kedua. |
| Pickup / shipped / selesai | Status pengiriman berubah di channel. | Tetap | 0 | Tetap | Status pesanan berubah, bukan mutasi stok gudang tambahan. |

### Contoh pesanan normal

Awalnya SKU A di rak adalah 10 unit.

| Kejadian | On hand | On order | Available | Penjelasan |
| --- | ---: | ---: | ---: | --- |
| Kondisi awal | 10 | 0 | 10 | Semua barang boleh dijual. |
| Pesanan 2 unit dibayar | 10 | 2 | 8 | Dua unit dikunci untuk pembeli. |
| Finish pick 2 unit | 8 | 0 | 8 | Barang benar-benar keluar dari rak dan faktur tercatat. |
| Barang dipacking dan dikirim | 8 | 0 | 8 | Tidak ada pengurangan tambahan. |

Perubahan `available` saat pesanan dibayar dan saat finish pick terlihat berbeda, tetapi hasil akhirnya konsisten: stok yang boleh dijual turun tepat 2 unit dan stok fisik juga turun tepat 2 unit.

## Pesanan yang tidak berhasil dipenuhi penuh

Jika saat picking ada barang kurang, rusak, atau ditolak, pesanan masuk status menunggu konfirmasi pembeli. Barang yang belum benar-benar selesai dipenuhi tidak boleh dianggap keluar begitu saja.

| Kondisi | Perlakuan stok |
| --- | --- |
| Item tidak ditemukan / short sebelum finish pick | Stok fisik belum dipotong. Cadangan pesanan tetap diawasi sampai keputusan pengganti, pengurangan qty, atau pembatalan selesai. |
| Pesanan dibatalkan sebelum finish pick | Cadangan dilepas. On hand tetap, on order turun, available naik kembali. |
| Hanya sebagian yang berhasil dipenuhi | Hanya qty yang benar-benar lolos proses akhir yang boleh difakturkan dan dipotong; sisa perlu diselesaikan melalui keputusan pesanan, bukan dipotong otomatis. |

## Pembatalan pesanan

Pembatalan dibedakan berdasarkan posisi fisik barang. Ini penting agar sistem tidak menganggap barang sudah kembali padahal paket masih berada di kurir atau pelanggan.

### 1. Cancel sebelum finish pick

Barang masih di rak dan belum keluar secara fisik.

| Dampak | Hasil |
| --- | --- |
| On hand | Tetap. |
| On order | Turun karena cadangan dilepas. |
| Available | Naik kembali. |
| Kronologi | `Pesanan Batal` / pelepasan cadangan pada tampilan lengkap. Tidak dianggap pengembalian barang fisik. |

### 2. Cancel setelah finish pick, tetapi sebelum pickup kurir

Barang sudah sempat keluar dari rak karena proses pick selesai, namun paket masih ada di area gudang.

| Dampak | Hasil |
| --- | --- |
| On hand | Naik kembali setelah barang dikembalikan ke rak asal/tujuan yang valid. |
| On order | Sudah 0 dari proses finish pick. |
| Available | Naik kembali. |
| Kronologi | `Pesanan Batal` dengan qty positif pada rak final. Muncul di kronologi bersih karena ada pergerakan fisik. |

### 3. Cancel setelah pickup atau shipped

Barang sudah tidak berada di penguasaan gudang. Sistem tidak boleh langsung menambah stok ke Gudang Kecil hanya karena channel mengirim status cancel.

Alurnya harus melalui retur:

1. Sistem membuat dokumen retur untuk pesanan tersebut dan menunggu paket fisik kembali.
2. Petugas meninjau detail SKU, qty, kondisi, serta alasan retur, lalu memilih **setujui** atau **tolak**.
3. Jika disetujui, sistem membuat dokumen penerimaan retur. Untuk pembatalan setelah shipped, penerimaan fisik dilakukan saat paket benar-benar tiba, bukan saat tombol setujui ditekan.
4. Paket diterima, qty dan kondisi dicatat. Barang masuk area inbound/retur sehingga belum dapat dijual.
5. Petugas membuat dan menyelesaikan putaway ke rak final.
6. Baru setelah putaway selesai, stok fisik dan stok tersedia bertambah kembali.

Jika retur ditolak, barang tidak ditambahkan ke stok jual. Jika kondisi barang rusak atau qty berbeda, discrepancy harus diselesaikan sesuai keputusan petugas sebelum barang dapat ditempatkan sebagai stok layak jual.

### Pilihan cepat saat ada cancel

| Pertanyaan | Jika jawabannya “ya” | Tindakan yang benar |
| --- | --- | --- |
| Apakah pesanan belum finish pick? | Barang masih di rak. | Lepas cadangan pesanan. Jangan membuat penerimaan atau retur. |
| Apakah sudah finish pick, tetapi belum pickup kurir? | Paket masih di gudang. | Pastikan barang benar-benar kembali ke rak final; stok baru bertambah setelah pengembalian fisik tercatat. |
| Apakah sudah pickup atau shipped? | Barang berada di kurir/pelanggan. | Buat/lanjutkan retur. Jangan tambah stok sebelum paket fisik diterima dan putaway selesai. |

## Retur penjualan

Retur bukan sekadar perubahan status channel. Retur adalah proses penerimaan barang fisik.

| Tahap retur | Status stok jual | Kronologi yang diharapkan |
| --- | --- | --- |
| Channel mengirim info retur/cancel shipped | Tidak berubah | Riwayat pesanan dan dokumen `RET-...` tercatat. |
| Retur disetujui | Tidak berubah | Riwayat menyebut petugas dan dokumen inbound/penerimaan yang dibuat. |
| Paket fisik diterima | Belum bertambah | Ada penerimaan retur pada area inbound. |
| Putaway ke rak final selesai | Bertambah | `Retur Penjualan` dan/atau pergerakan penempatan fisik ke rak final tercatat. |
| Retur diselesaikan | Tidak mengubah angka lagi | Riwayat menutup proses sebagai selesai. |

Riwayat pesanan yang baik untuk retur minimal menjelaskan: paket dibatalkan setelah pickup/shipped, nomor retur, keputusan petugas, nomor penerimaan, SKU/qty/kondisi saat paket diterima, dan rak tujuan saat putaway selesai.

### Contoh lengkap: satu SKU, tiga jenis pembatalan

Misalnya SKU A di rak final awalnya 10 unit dan ada satu pesanan berisi 2 unit.

| Tahap yang terjadi | On hand | On order | Available | Baris/riwayat yang harus dilihat |
| --- | ---: | ---: | ---: | --- |
| Pesanan dibayar | 10 | 2 | 8 | Riwayat pesanan dibayar/diproses; `Pesanan`/cadangan pada tampilan semua. |
| Pesanan batal sebelum finish pick | 10 | 0 | 10 | Riwayat pembatalan; cadangan dilepas. Tidak ada faktur atau stok fisik masuk. |
| Pesanan lain finish pick 2 unit | 8 | 0 | 8 | Riwayat `FINISH_PICK`; faktur dan `Faktur -2` dari rak hasil scan. |
| Pesanan itu batal sebelum pickup, barang benar-benar dikembalikan ke rak | 10 | 0 | 10 | Riwayat pembatalan dan `Pesanan Batal +2` pada rak final. |
| Pesanan berikutnya finish pick 2 unit lalu pickup/shipped | 8 | 0 | 8 | Faktur `-2`, lalu riwayat pickup/shipped. |
| Pesanan terakhir dibatalkan setelah pickup/shipped | 8 | 0 | 8 | `RET-...` dibuat; **belum** ada `+2` stok. |
| Paket retur tiba dan diterima di inbound | 8 | 0 | 8 | Riwayat `INB-...`, SKU A × 2 dan kondisi barang; belum tersedia karena belum di rak final. |
| Putaway retur 2 unit ke rak final selesai | 10 | 0 | 10 | Riwayat putaway; `Retur Penjualan +2` pada rak final. |

Jadi perbedaan utamanya sederhana: cancel sebelum kurir mengambil paket dapat mengembalikan barang ke rak karena barang masih dikuasai gudang. Setelah kurir mengambil paket, angka tidak boleh naik sampai paket yang sama benar-benar diterima dan ditempatkan kembali ke rak.

## Penerimaan barang masuk

Aturan ini berlaku untuk pembelian, konsinyasi, transfer masuk, dan retur yang benar-benar tiba.

| Tahap | On hand rak final | Available | Penjelasan |
| --- | ---: | ---: | --- |
| Dokumen inbound dibuat | Tetap | Tetap | Baru rencana penerimaan. |
| Barang diterima di bin inbound | Tetap | Tetap | Barang ada secara fisik, tetapi belum boleh dipilih atau dijual. |
| QC/cek qty dan kondisi | Tetap | Tetap | Qty rusak/selisih dipisahkan dan dicatat. |
| Putaway ke rak final | Naik | Naik | Barang menjadi stok gudang yang dapat dijual, setelah memperhitungkan on order. |

Contoh: 20 unit diterima dari pemasok. Selama masih berada di bin inbound, available tidak berubah. Setelah 20 unit dipindahkan ke rak final, on hand bertambah 20; available ikut bertambah 20 bila tidak ada pesanan yang mencadangkannya.

## Putaway dan temporary zone

Putaway adalah bukti bahwa barang sudah ditempatkan ke rak tujuan. Sistem menggunakan dokumen penempatan agar sumber barang, qty, petugas, dan rak tujuan tercatat bersama.

Temporary zone bukan rak jual. Barang cancel/overpick yang belum diverifikasi, barang retur yang baru datang, atau barang yang menunggu keputusan harus tetap non-sellable di area tersebut. Stok baru kembali tersedia setelah verifikasi dan putaway selesai ke rak final.

Ini menghindari picker mencari SKU yang di sistem terlihat ada, padahal fisiknya masih berada di meja checker, area retur, atau keranjang temporary.

## Transfer stok

### Pindah rak dalam gudang yang sama

Pindah bin hanya mengubah posisi barang, bukan jumlah total gudang.

| Pergerakan | Rak asal | Rak tujuan | Total gudang |
| --- | ---: | ---: | ---: |
| Transfer bin 5 unit | -5 | +5 | Tetap |

Kronologi dapat memuat baris keluar dari rak asal dan masuk ke rak tujuan. Untuk membaca total gudang, kedua baris tersebut adalah satu proses transfer, bukan stok keluar dua kali.

### Transfer antar gudang

| Tahap | Gudang asal | Transit | Gudang tujuan | Stok jual tujuan |
| --- | ---: | ---: | ---: | ---: |
| Transfer dikirim | Berkurang | Bertambah | Tetap | Tetap |
| Transfer diterima | Tetap | Berkurang | Masuk bin inbound | Tetap |
| Putaway tujuan selesai | Tetap | Tetap | Bertambah di rak final | Bertambah |

Barang transit tidak dihitung sebagai stok tersedia di gudang tujuan sampai putaway selesai.

### Pecah stok

Pecah stok dipakai bila satu SKU fisik diubah menjadi SKU lain dengan rasio yang sudah disetujui, misalnya satu paket menjadi beberapa unit satuan. Ini bukan penerimaan pembelian dan bukan pesanan penjualan.

| Yang terjadi | Cara membaca kronologi |
| --- | --- |
| SKU sumber berkurang | Ada baris `Pecah Stok` qty negatif. |
| SKU hasil bertambah | Ada baris `Pecah Stok` qty positif. |
| Jumlah unit dapat berbeda | Ikuti rasio dokumen pecah stok; satu paket dapat menjadi beberapa unit, sehingga total angka unit tidak harus sama. |

Staff tidak boleh memakai adjustment untuk menggantikan proses pecah stok. Pecah stok harus memakai dokumen sendiri agar SKU sumber, SKU hasil, rasio, rak, qty, dan pelaksananya dapat ditelusuri.

## Penyesuaian stok dan stock opname

Penyesuaian dan stock opname dipakai untuk menyamakan sistem dengan kondisi fisik setelah ada pemeriksaan yang sah. Keduanya bukan pengganti proses pesanan, inbound, atau retur.

| Aktivitas | Dampak | Yang wajib ada di kronologi |
| --- | --- | --- |
| Penyesuaian positif | On hand dan available bertambah. | Nomor dokumen, qty positif, alasan, pelaksana. |
| Penyesuaian negatif | On hand dan available berkurang. | Nomor dokumen, qty negatif, alasan, pelaksana. |
| Stock opname | Sistem menghitung selisih dari hasil hitung fisik dan membuat penyesuaian. | Dokumen opname, qty selisih, rak dan petugas. |
| Koreksi penerimaan | Mengoreksi qty yang diterima sebelum/selama penempatan sesuai batas yang aman. | Hubungan ke dokumen inbound dan alasan koreksi. |

Apabila sebuah dokumen dibatalkan atau diperbaiki, sistem membuat jejak koreksi/reversal. Jejak tersebut tidak boleh dihapus dari audit; tampilan kronologi dapat men-net-kan pasangan koreksi agar daftar utama tetap mudah dibaca.

## Pembelian, konsinyasi, dan retur pembelian

Tidak semua barang masuk berasal dari pesanan pembelian biasa. Namun prinsip stok jualnya sama: barang baru menjadi available setelah ditempatkan ke rak final.

| Proses | Kapan qty stok berubah | Dampak terhadap stok jual |
| --- | --- | --- |
| Pembelian | Saat penerimaan fisik lalu putaway selesai. | Naik saat masuk rak final. |
| Konsinyasi | Saat barang konsinyasi diterima lalu putaway selesai. | Naik saat masuk rak final, dengan sumber `Konsinyasi`. |
| Retur pembelian | Saat barang dikirim/ditarik kembali dari rak sesuai dokumen retur pembelian. | Turun pada qty yang benar-benar keluar. |
| Pembatalan atau koreksi penerimaan pembelian | Sistem hanya boleh menarik balik qty yang masih ada di bin inbound atau rak asal yang relevan. | Turun sesuai qty koreksi; proses ditolak bila barangnya sudah terpakai/keluar dan tidak cukup untuk ditarik. |

Dokumen pembelian, tagihan, dan konsinyasi tidak otomatis berarti stok bertambah. Yang menjadi bukti qty adalah penerimaan fisik dan putaway, bukan tanggal dokumen administratifnya.

## Nilai stok tidak sama dengan jumlah stok

Sistem menyimpan dua jenis informasi yang berbeda.

| Perubahan | Mengubah qty on hand / available? | Contoh |
| --- | --- | --- |
| Penerimaan, finish pick, retur, transfer, adjustment, opname | Ya. | Barang benar-benar berpindah atau selisih fisik dikoreksi. |
| Revaluasi / ubah nilai stok | Tidak. | Harga modal diperbarui, tetapi jumlah unit di rak tetap sama. |
| Pembayaran, settlement, komisi, diskon, biaya kirim | Tidak. | Mengubah pencatatan finansial pesanan, bukan qty gudang. |

Karena itu jangan memakai perubahan nilai persediaan atau status pencairan marketplace sebagai bukti stok fisik bertambah/berkurang.

## Pesanan manual dan penyelesaian langsung

Pesanan dari semua jalur—Shopee, TikTok, Lazada, pesanan manual, maupun alur penyelesaian langsung—harus berakhir pada prinsip yang sama: hanya barang yang benar-benar diambil dari rak yang boleh mengurangi on hand.

| Jalur | Titik cadangan | Titik pemotongan fisik | Jejak utama |
| --- | --- | --- | --- |
| Pesanan channel | Saat pesanan valid/aktif dan sudah perlu dialokasikan. | Finish pick dan pembuatan faktur dari scan rak. | Pesanan, faktur, picklist. |
| Pesanan manual | Saat admin membuat pesanan aktif. | Finish pick/faktur atau penyelesaian langsung yang mencatat rak asal. | Pesanan manual, faktur, mutasi rak. |
| Penyelesaian langsung | Sesuai aturan pesanan, bila cadangan sudah ada. | Saat operator memilih rak dan qty yang benar-benar dikeluarkan. | Faktur dan mutasi fisik dari rak asal. |

Tidak ada jalur yang boleh mengurangi stok hanya berdasarkan tombol status. Jika rak asal atau qty fisik tidak jelas, proses harus dihentikan untuk mencegah pemotongan tanpa jejak.

## Bundle

Bundle tidak memiliki stok fisik terpisah. Sistem menghitung kemampuan jual bundle dari komponen-komponennya.

```text
stok bundle = nilai paling kecil dari
floor(available komponen / kebutuhan komponen per bundle)
```

Contoh bundle membutuhkan 1 adaptor dan 2 kabel:

| Komponen | Available | Kebutuhan per bundle | Kemampuan bundle |
| --- | ---: | ---: | ---: |
| Adaptor | 8 | 1 | 8 bundle |
| Kabel | 11 | 2 | 5 bundle |
| Stok bundle yang boleh dijual |  |  | 5 bundle |

Saat sebuah bundle dipesan, sistem mencadangkan setiap komponennya. Saat finish pick, sistem memotong komponen yang benar-benar dipindai dari rak. Karena itu SKU bundle dan komponen harus memiliki mapping yang benar; sistem tidak membuat stok fisik baru untuk SKU bundle.

## Push stok ke channel

Dalam keadaan operasi normal, angka yang dikirim ke setiap toko/channel adalah `available` dari lokasi sumber toko tersebut, setelah dikurangi buffer toko bila buffer diatur.

```text
stok yang dikirim ke channel = max(0, available lokasi sumber - buffer toko)
```

Untuk bundle, angka yang dikirim adalah hasil perhitungan komponen seperti bagian bundle di atas.

Selama cutover/reset stok, sinkronisasi pesanan tetap boleh berjalan agar pesanan baru tidak hilang. Namun push stok dan push fulfillment perlu tetap dimatikan sampai stok awal selesai diimpor dan rekonsiliasi selesai. Tujuannya agar angka 0 atau angka sementara tidak terlanjur dikirim ke semua marketplace.

## Integrasi channel: batas tanggung jawab dan pencegahan duplikasi

Channel mengirim status pesanan, pembatalan, pengiriman, dan retur. Sistem menyimpan event tersebut terlebih dahulu, lalu memprosesnya menjadi perubahan pesanan. Perubahan stok hanya terjadi setelah status tersebut melewati aturan stok di dokumen ini.

| Kejadian dari channel | Yang boleh berubah langsung | Yang tidak boleh dilakukan langsung |
| --- | --- | --- |
| Pesanan baru/berbayar | Pesanan dan cadangan stok. | Mengurangi stok fisik tanpa finish pick. |
| Cancel sebelum finish pick | Pelepasan cadangan. | Menambah on hand, karena barang masih ada di rak. |
| Cancel setelah pickup/shipped | Dokumen retur. | Menambah stok Gudang Kecil tanpa paket fisik kembali. |
| Status packing, manifest, shipped | Status dan riwayat pesanan. | Memotong stok kedua kali. |
| Event yang sama terkirim ulang | Tidak ada perubahan kedua. | Membuat pesanan, cadangan, penerimaan, atau mutasi ganda. |

Setiap event dan penerimaan penting memakai identitas unik/idempotensi. Sederhananya, jika perangkat atau channel mengirim pesan yang sama dua kali, sistem mengenalinya sebagai pesan yang sama sehingga stok tidak terpotong atau bertambah dua kali.

Apabila channel sedang gagal merespons, sistem menyimpan event/pekerjaan untuk dicoba ulang. Kegagalan komunikasi bukan alasan untuk mengubah stok secara manual; yang diperiksa adalah status event, antrean, dan hasil pemanggilan ulangnya.

## Cara membaca halaman kronologi stok

### Tampilan “semua”

Tampilan ini adalah buku besar audit. Ia dapat memuat:

- cadangan pesanan dan pelepasannya,
- penerimaan ke bin inbound,
- putaway,
- picking/faktur,
- transfer dan transit,
- retur,
- penyesuaian, stock opname, serta koreksi,
- baris migrasi/backfill yang ditandai secara jelas.

Gunakan tampilan ini saat mencari penyebab perubahan angka atau memeriksa urutan penuh sebuah nomor pesanan/dokumen.

Tampilan “semua” bukan salinan mentah database baris per baris. Jika satu mutasi sudah dibatalkan oleh mutasi pasangannya, pasangan tersebut disembunyikan bersama dari daftar agar satu proses yang hasil akhirnya nol tidak terlihat seperti dua perubahan stok. Jejak audit dan hubungan koreksinya tetap tersimpan. Untuk investigasi, cari nomor dokumen asal dan koreksinya sebagai satu rangkaian, bukan sebagai dua transaksi terpisah.

### Tampilan “kronologi bersih”

Tampilan ini dipakai untuk membaca perubahan fisik yang relevan bagi stock opname. Ia hanya menampilkan pergerakan yang benar-benar menyentuh rak final, misalnya:

- putaway ke rak final,
- faktur dari finish pick,
- picking/koreksi picking fisik,
- retur penjualan,
- transfer fisik antar rak atau gudang,
- pengembalian fisik karena pesanan dibatalkan sebelum pickup.

Cadangan pesanan, pelepasan cadangan tanpa pemindahan fisik, area inbound, dan staging default tidak ditampilkan sebagai perpindahan rak final pada kronologi bersih. Ini disengaja agar tim tidak mengira stok berpindah fisik padahal hanya ada perubahan status pesanan.

### Arti kolom saldo

`Saldo setelah aktivitas` adalah saldo historis segera setelah baris itu terjadi. Ia bukan selalu angka stok saat ini karena setelahnya bisa ada transaksi lain.

Halaman juga menyediakan ringkasan stok saat ini. Ringkasan ini dihitung dari kondisi inventori terbaru, sehingga tetap sama antara tampilan “semua” dan “kronologi bersih” walaupun jumlah barisnya berbeda.

## Sumber kronologi dan maknanya

| Label di layar | Makna operasional | Umumnya masuk kronologi bersih? |
| --- | --- | --- |
| Pesanan | Cadangan stok karena pesanan aktif. | Tidak. |
| Pesanan Batal | Pelepasan cadangan, atau pengembalian fisik bila barang sudah selesai dipick dan dikembalikan ke rak. | Hanya jika ada pengembalian fisik ke rak final. |
| Faktur | Pemotongan stok hasil finish pick. | Ya, jika berasal dari rak final. |
| Barang di-pick | Jejak pengambilan fisik bila dipakai oleh alur terkait. | Ya, jika dari rak final. |
| Retur Penjualan | Barang retur yang telah diterima dan diproses. | Ya. |
| Transfer | Perpindahan antar bin/gudang. | Ya, untuk pergerakan pada rak final. |
| Tagihan / penerimaan | Penerimaan pembelian atau penempatan; lihat raknya untuk membedakan inbound dan rak final. | Putaway ke rak final ya; staging inbound tidak. |
| Penyesuaian | Koreksi angka karena audit/opname/penerimaan. | Tergantung jenis dan konteks audit. |
| Koreksi Faktur / Koreksi Pick | Pembalikan transaksi sebelumnya yang harus dapat ditelusuri. | Ya bila mengubah rak final. |

## Riwayat pesanan: apa yang wajib tercatat

Halaman riwayat pesanan dan halaman kronologi stok menjawab pertanyaan yang berbeda. Riwayat pesanan menjawab **siapa melakukan apa dan kapan**. Kronologi stok menjawab **SKU, qty, dan rak mana yang berubah**. Untuk kejadian yang benar-benar mengubah stok fisik, keduanya harus dapat ditelusuri melalui nomor pesanan/faktur/retur yang sama.

| Kejadian | Riwayat pesanan yang tercatat | Dampak pada kronologi stok |
| --- | --- | --- |
| Pesanan dibuat, dibayar, atau masuk proses | Pesanan dibuat/dibayar/diproses. | Cadangan dapat terlihat di tampilan semua; belum ada pengurangan fisik. |
| Picker mulai atau gagal pick | Mulai pick atau gagal pick, beserta alasan bila ada. | Belum ada mutasi fisik final. |
| Finish pick | Finish pick, faktur dibuat, SKU/qty/rak hasil scan dapat ditelusuri dari dokumen. | `Faktur` qty negatif dari rak final. |
| Packing, label, manifest, kurir dipanggil | Mulai/selesai pack, label, siap kirim, kurir dipanggil, atau nomor resi diperbarui. | Tidak ada pemotongan kedua. Packing bahkan ditolak bila finish pick belum membuat komitmen fisik/faktur. |
| Channel mengubah status | Status channel, status dikirim, selesai, atau diterima pembeli. | Tidak ada mutasi gudang tambahan hanya karena status berubah. |
| Cancel sebelum pickup | Pembatalan pesanan dan alasan/status channel. | Pelepasan cadangan atau pengembalian fisik ke rak bila barang memang sudah selesai dipick tetapi masih di gudang. |
| Cancel setelah pickup/shipped | Pembatalan setelah paket berada di kurir/pelanggan. | Tidak ada penambahan stok langsung; proses berlanjut ke retur. |
| Retur dibuat | `Dokumen retur RET-... dibuat — menunggu paket fisik.` | Belum ada stok kembali. |
| Retur disetujui/ditolak | Keputusan petugas dan alasan bila ditolak. | Disetujui pun belum menambah stok; ditolak tidak menambah stok. |
| Penerimaan retur dibuat/dipindai | Nomor `INB-...`, SKU, qty, serta kondisi barang yang diterima. | Barang masuk inbound/non-sellable, belum tersedia. |
| Putaway retur selesai | Rak tujuan dan status retur selesai. | `Retur Penjualan`/putaway menambah stok pada rak final sehingga available ikut bertambah. |
| Pesanan dipindahkan ke/dari shipment | Pesanan ditambahkan, dihapus, atau diserahkan ke shipment. | Hanya jejak operasional, bukan pemotongan stok baru. |

Sistem mencegah riwayat retur ganda dengan identitas kejadian yang sama. Jadi webhook atau pemindaian yang terkirim ulang tidak boleh menciptakan dua dokumen retur, dua penerimaan, atau dua penambahan stok.

## Urutan riwayat pesanan yang seharusnya terlihat

Untuk pesanan normal yang selesai diproses, riwayat idealnya dapat dibaca seperti ini:

1. Pesanan diterima dan stok dicadangkan.
2. Picker mulai mengambil barang dari rak.
3. Finish pick berhasil; faktur dibuat dan stok fisik dipotong sesuai SKU, qty, dan rak hasil scan.
4. Barang dipacking/manifest.
5. Barang di-pickup atau dikirim oleh kurir.

Untuk pembatalan setelah shipped/pickup, riwayat dilanjutkan seperti ini:

1. Pesanan dibatalkan setelah paket diambil kurir.
2. Dokumen retur `RET-...` dibuat dan menunggu paket fisik.
3. Retur disetujui oleh petugas; dokumen penerimaan dibuat.
4. Paket diterima, lalu SKU, qty, dan kondisi dicatat.
5. Putaway selesai ke rak tujuan; stok kembali tersedia.

## Checklist sebelum menyimpulkan stok salah

1. Pastikan filter gudang dan SKU yang dilihat sudah benar.
2. Bandingkan `on hand`, `on order`, dan `available`; jangan hanya melihat satu angka.
3. Cari nomor pesanan, faktur, retur, inbound, atau transfer pada tampilan “semua”.
4. Periksa apakah barang masih di inbound, temporary zone, atau transit.
5. Untuk pesanan, tentukan apakah hanya dicadangkan, sudah finish pick, atau sudah pickup/shipped.
6. Untuk cancel setelah shipped, cari dokumen retur; jangan mengharapkan stok langsung kembali.
7. Untuk bundle, periksa seluruh komponen dan qty kebutuhan bundle.
8. Periksa apakah baris adalah data `system:backfill`; data ini menjelaskan migrasi lama, bukan aktivitas scan baru.

## Rekonsiliasi dan kontrol harian

Rekonsiliasi berarti membandingkan tiga hal: barang di rak, angka inventori saat ini, dan kronologi dokumennya. Tujuannya menemukan selisih sebelum selisih itu menyebar ke channel.

| Kontrol | Yang dibandingkan | Jika berbeda |
| --- | --- | --- |
| Rak final | Hasil scan/hitung fisik vs on hand per rak. | Buat stock opname atau adjustment beralasan setelah verifikasi. |
| Pesanan aktif | On order vs pesanan yang masih pending/reserved/menunggu konfirmasi. | Rekonsiliasi cadangan; jangan mengubah on hand untuk masalah cadangan. |
| Finish pick | Qty scan/alokasi vs faktur dan mutasi `Faktur`. | Hentikan manifest lanjutan bila faktur atau mutasi belum lengkap. |
| Penerimaan | Qty penerimaan vs bin inbound vs dokumen putaway. | Selesaikan selisih penerimaan atau putaway sebelum menjual barangnya. |
| Transfer | Qty keluar asal vs transit vs qty diterima tujuan. | Cari dokumen transfer dan penerimaan terkait; jangan adjustment pada kedua sisi sekaligus. |
| Retur | Dokumen retur, penerimaan fisik, kondisi, dan putaway. | Barang tidak boleh menjadi available sebelum seluruh langkah fisik selesai. |
| Channel | Available lokasi sumber vs angka terakhir yang sukses dipush. | Periksa mapping SKU, toko, buffer, event sync, dan antrean; lakukan retry terkontrol. |

Audit integritas transaksi dapat digunakan untuk mendeteksi pasangan dokumen atau mutasi yang tidak lengkap. Audit bersifat pemeriksaan; perbaikannya tetap harus memakai proses koreksi yang memiliki jejak, bukan menghapus baris mutasi.

## Aturan praktis untuk tim gudang

- Jangan menambah stok karena status channel berubah cancel apabila paket sudah pickup/shipped; gunakan retur.
- Jangan menganggap barang di inbound atau temporary zone sebagai stok jual.
- Selesaikan putaway setelah penerimaan agar barang benar-benar masuk rak dan tersedia.
- Pastikan scan finish pick lengkap; di titik ini sistem membuat faktur dan memotong stok fisik.
- Gunakan adjustment/opname hanya untuk selisih fisik yang telah diverifikasi, dengan alasan yang jelas.
- Untuk investigasi, gunakan nomor dokumen yang sama dari awal sampai akhir: pesanan, faktur, retur/inbound, atau transfer.
- Jangan menghapus mutasi secara manual. Bila ada kesalahan, gunakan pembatalan atau koreksi agar audit tetap utuh.
- Jika scan, webhook, atau penerimaan dikirim ulang, gunakan nomor dokumen dan hasil audit untuk memastikan sistem tidak menjalankan efek stok kedua kali.

## Tugas setiap peran

| Peran | Yang dilakukan | Titik yang harus dipastikan |
| --- | --- | --- |
| Picker | Scan SKU dan rak asal, lalu selesaikan pick hanya jika qty benar. | Rak dan qty scan harus sesuai barang yang diambil. |
| Checker/manifest | Memastikan pesanan yang finish pick benar-benar sesuai sebelum dikemas/dikirim. | Jangan menganggap manifest sebagai pemotongan stok kedua. |
| Penerima barang | Mencatat barang datang, qty, kondisi, dan selisihnya di inbound/retur. | Barang masih belum boleh dijual saat berada di inbound. |
| Staff putaway | Memindahkan barang dari inbound/temporary ke rak tujuan dengan scan SKU dan rak. | Barang menjadi stok jual saat putaway ke rak final selesai. |
| Leader/PIC | Menyetujui atau menolak retur, selisih, dan adjustment berdasarkan bukti fisik. | Untuk paket setelah shipped, pastikan paket benar-benar diterima sebelum restock. |
| Admin | Menjaga mapping SKU, lokasi sumber channel, buffer, dan dokumen koreksi. | Jangan mengganti angka stok langsung tanpa dokumen dan alasan. |

## Kapan harus berhenti dan minta bantuan

Jangan lanjutkan proses atau membuat adjustment jika salah satu kondisi berikut terjadi:

- SKU yang discan tidak sesuai dengan pesanan atau dokumen penerimaan.
- Rak asal/tujuan tidak tersedia atau tidak sama dengan barang fisik.
- Qty fisik berbeda dengan qty dokumen.
- Pesanan sudah pickup/shipped tetapi ada instruksi untuk langsung menambah stok.
- Faktur tidak terbentuk setelah finish pick.
- Barang terlihat di inbound, temporary, atau transit tetapi muncul sebagai stok yang boleh dijual.
- Satu nomor pesanan atau dokumen terlihat menambah/mengurangi stok lebih dari sekali.

Catat nomor pesanan/dokumen, SKU, qty, rak, dan foto bila perlu. Informasi tersebut cukup untuk leader atau tim sistem menelusuri kronologi tanpa menebak-nebak.

## Batasan data migrasi dan cutover

Sebelum sistem menjadi sumber utama, data backfill dapat memiliki urutan waktu berbeda: transaksi historis dapat masuk belakangan, atau catatan fisik lama tidak memiliki alokasi rak hasil scan di sistem ini. Karena itu data tersebut diberi penanda migrasi dan tidak boleh dipakai sebagai standar untuk membaca alur baru.

Setelah reset/cutover selesai, stok awal harus diimpor ke rak final, pesanan aktif direkonsiliasi menjadi cadangan yang benar, lalu stok channel baru diaktifkan. Dengan urutan tersebut, seluruh transaksi baru mengikuti satu alur: cadangkan saat pesanan aktif, potong fisik saat finish pick, dan kembalikan stok hanya ketika barang benar-benar kembali ke rak melalui proses yang sesuai.

## Lampiran: kamus lengkap sumber mutasi stok

Bagian ini dipakai admin/leader saat audit. Staf gudang tidak perlu menghafal kode-kode ini; gunakan label di layar dan nomor dokumen. Seluruh sumber mutasi yang dikenali sistem saat dokumen ini diperiksa tercakup di bawah ini.

| Kelompok dan kode sistem | Arti sederhana | Pengaruh utama |
| --- | --- | --- |
| Penerimaan: `PURCHASE`, `BILL`, `CONSIGNMENT`, `INBOUND_QTY_CORRECTION`, `PURCHASE_REVERSAL`, `BACKFILL_INBOUND_RESTORE` | Barang pembelian/konsinyasi diterima, dikoreksi, atau data penerimaan lama diperbaiki. | Masuk awal biasanya ke inbound; hanya qty yang akhirnya ditempatkan ke rak final yang menjadi stok jual. |
| Putaway: `PUTAWAY_IN`, `PUTAWAY_OUT`, `PUTAWAY_REVERSAL` | Barang dipindahkan dari area penerimaan ke rak, dipindahkan kembali, atau dikoreksi. | Perpindahan ke rak final menambah on hand/available; pembalikannya harus merujuk dokumen yang sama. |
| Penyesuaian: `ADJUSTMENT`, `STOCK_OPNAME`, `REVALUATION` | Koreksi qty audit, hasil opname, atau perubahan nilai modal. | Adjustment/opname mengubah qty sesuai bukti; revaluation hanya nilai, bukan qty. |
| Retur: `SALES_RETURN`, `PURCHASE_RETURN` | Barang kembali dari pelanggan atau dikembalikan ke pemasok. | Retur penjualan baru menjadi stok jual setelah penerimaan dan putaway; retur pembelian mengurangi qty yang benar-benar keluar. |
| Faktur alur aktif: `INVOICE` | Barang hasil finish pick sudah keluar dari rak dan faktur dibuat. | Pengurangan fisik tunggal pada rak final. |
| Faktur/picking lama atau kompatibilitas: `ORDER_PICK`, `ORDER_SHIP`, `ORDER_COMPLETE_OUT`, `ORDER_COMPLETE_REVERSAL`, `PICKING`, `PICKING_REVERSAL`, `PACKING`, `PACKING_REVERSAL` | Jejak dari alur lama, historis, atau koreksi atasnya. | Dipakai untuk membaca/mengoreksi data lama. Pada alur aktif, packing tidak lagi memotong stok kedua kali; pemotongan normal memakai `INVOICE` saat finish pick. |
| Cadangan pesanan: `ORDER`, `ORDER_RESERVE`, `RESERVE` | Barang dijanjikan ke pesanan aktif. | Mengurangi available melalui on order, bukan mengurangi on hand. |
| Pelepasan/pengembalian pesanan: `ORDER_RELEASE`, `ORDER_CANCELLED`, `RESERVE_CANCEL`, `RESERVE_EXPIRED`, `ORDER_RESTORE`, `ORDER_RESTORE_CANCEL` | Cadangan dibatalkan/kedaluwarsa atau barang fisik yang masih di gudang dikembalikan ke rak. | Pelepasan cadangan saja tidak mengubah fisik; `ORDER_RESTORE*` dapat menambah fisik bila barang benar-benar dipindahkan kembali ke rak final. |
| Transfer antar gudang/rak: `TRANSFER_OUT`, `TRANSFER_IN`, `TRANSFER_REVERT`, `TRANSIT_IN`, `TRANSIT_OUT`, `TRANSIT_REVERT_IN`, `BIN_TRANSFER_OUT`, `BIN_TRANSFER_IN`, `BIN_TRANSFER_REVERSAL`, `BIN_TRANSFER_REVERT_OUT`, `TRANSFER_REJECT_RETURN` | Barang keluar dari rak/gudang, berada di transit, diterima, dipindah bin, atau proses transfer dibatalkan/ditolak. | Mengubah lokasi barang, bukan menciptakan atau menghilangkan qty total tanpa dokumen koreksi. Stok jual tujuan hanya naik setelah berada di rak final. |
| Pecah stok: `SPLIT_OUT`, `SPLIT_IN` | SKU sumber dipecah menjadi SKU hasil berdasarkan rasio dokumen. | SKU sumber berkurang dan SKU hasil bertambah sesuai rasio yang disahkan. |

### Cara audit sumber lama dan koreksi

- `ORDER_COMPLETE_OUT` dan `ORDER_COMPLETE_REVERSAL` yang dibuat oleh `system:backfill` adalah hasil migrasi, bukan bukti scan baru oleh staf.
- `PACKING` dan `PACKING_REVERSAL` tetap dikenali agar histori lama dapat dibaca atau dikoreksi dengan aman, tetapi proses packing baru hanya memvalidasi bahwa stok telah dicatat di finish pick.
- `TRANSFER_REVERT`, `BIN_TRANSFER_REVERSAL`, `BIN_TRANSFER_REVERT_OUT`, `TRANSIT_REVERT_IN`, dan `PUTAWAY_REVERSAL` adalah pembalik proses yang sudah ada. Jangan menganggapnya sebagai penerimaan/pengeluaran tambahan tanpa melihat transaksi asal.
- Jika kode sumber tidak ada di daftar ini, itu harus dianggap perubahan sistem baru dan wajib ditambahkan ke panduan, pengujian, serta audit sebelum dipakai operasional.

## Lampiran: kamus lengkap event riwayat pesanan

Kode berikut adalah jenis aktivitas yang dapat muncul pada riwayat pesanan. Di layar, sistem menampilkannya sebagai kalimat yang mudah dibaca; kode ini hanya diperlukan bila tim sistem melakukan audit.

| Tahap | Event sistem | Yang dibuktikan di riwayat |
| --- | --- | --- |
| Pembuatan dan pembayaran | `CREATED`, `ITEM_CREATED`, `PAID`, `PROCESS`, `FIELD_CHANGED`, `ZONE_ASSIGNED` | Pesanan/item dibuat, pembayaran atau data berubah, pesanan mulai diproses, dan zona kerja ditentukan. |
| Picking | `PICK_STARTED`, `PICK_FAILED`, `FINISH_PICK` | Pengambilan dimulai, ada kegagalan/selisih, atau pick selesai. Pada finish pick yang valid, cek juga faktur dan mutasi `INVOICE`. |
| Packing dan pengiriman | `PACK_STARTED`, `LABEL_PRINTED`, `FINISH_PACK`, `READY_TO_SHIP`, `DRIVER_CALLED`, `TRACKING_UPDATED`, `ADDED_TO_SHIPMENT`, `REMOVED_FROM_SHIPMENT`, `SHIPMENT_HANDED_OVER` | Paket dikemas, label/resi dibuat, siap diserahkan, status shipment berubah, atau paket benar-benar diserahkan. Tidak satu pun menggantikan bukti finish pick untuk stok fisik. |
| Status channel dan akhir pesanan | `CHANNEL_STATUS`, `SHIPPED`, `RECEIVED_BY_BUYER`, `COMPLETED`, `CANCELLED` | Perubahan yang diterima dari channel atau hasil akhir pengiriman/pembatalan. Status ini menentukan aturan proses, tetapi tidak otomatis memindahkan stok tanpa dokumen fisik. |
| Keputusan dan proses retur | `RETURN_DECISION`, `RETURN_CREATED`, `RETURN_ACCEPTED`, `RETURN_REJECTED`, `RETURN_INBOUND_CREATED`, `RETURN_RECEIVED`, `RETURN_PUTAWAY_COMPLETED`, `RETURN_COMPLETED` | Keputusan petugas, nomor retur/penerimaan, penerimaan fisik, putaway, hingga retur ditutup. Rangkaian ini wajib lengkap untuk retur setelah pickup/shipped sebelum stok kembali tersedia. |

Jika salah satu kejadian fisik tidak memiliki pasangannya—misalnya `FINISH_PICK` tanpa faktur/mutasi `INVOICE`, atau `RETURN_RECEIVED` tanpa `RETURN_PUTAWAY_COMPLETED` saat barang belum ada di rak final—operasional harus berhenti pada dokumen tersebut dan meminta audit. Jangan menggantinya dengan adjustment tanpa investigasi.
