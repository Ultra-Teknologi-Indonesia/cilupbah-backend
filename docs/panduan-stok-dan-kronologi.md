# Panduan stok dan kronologi

Dokumen ini menjelaskan cara sistem menghitung dan mencatat stok dari seluruh proses operasional. Tujuannya agar tim gudang, operasional, dan admin channel membaca angka yang sama dengan cara yang sama.

Dokumen ini berlaku untuk alur sistem yang aktif saat ini. Baris berpenanda `system:backfill` adalah data migrasi lama; baris tersebut dipisahkan agar tidak dianggap sebagai aktivitas scan yang dilakukan di sistem ini.

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

## Penyesuaian stok dan stock opname

Penyesuaian dan stock opname dipakai untuk menyamakan sistem dengan kondisi fisik setelah ada pemeriksaan yang sah. Keduanya bukan pengganti proses pesanan, inbound, atau retur.

| Aktivitas | Dampak | Yang wajib ada di kronologi |
| --- | --- | --- |
| Penyesuaian positif | On hand dan available bertambah. | Nomor dokumen, qty positif, alasan, pelaksana. |
| Penyesuaian negatif | On hand dan available berkurang. | Nomor dokumen, qty negatif, alasan, pelaksana. |
| Stock opname | Sistem menghitung selisih dari hasil hitung fisik dan membuat penyesuaian. | Dokumen opname, qty selisih, rak dan petugas. |
| Koreksi penerimaan | Mengoreksi qty yang diterima sebelum/selama penempatan sesuai batas yang aman. | Hubungan ke dokumen inbound dan alasan koreksi. |

Apabila sebuah dokumen dibatalkan atau diperbaiki, sistem membuat jejak koreksi/reversal. Jejak tersebut tidak boleh dihapus dari audit; tampilan kronologi dapat men-net-kan pasangan koreksi agar daftar utama tetap mudah dibaca.

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

## Aturan praktis untuk tim gudang

- Jangan menambah stok karena status channel berubah cancel apabila paket sudah pickup/shipped; gunakan retur.
- Jangan menganggap barang di inbound atau temporary zone sebagai stok jual.
- Selesaikan putaway setelah penerimaan agar barang benar-benar masuk rak dan tersedia.
- Pastikan scan finish pick lengkap; di titik ini sistem membuat faktur dan memotong stok fisik.
- Gunakan adjustment/opname hanya untuk selisih fisik yang telah diverifikasi, dengan alasan yang jelas.
- Untuk investigasi, gunakan nomor dokumen yang sama dari awal sampai akhir: pesanan, faktur, retur/inbound, atau transfer.
- Jangan menghapus mutasi secara manual. Bila ada kesalahan, gunakan pembatalan atau koreksi agar audit tetap utuh.

## Batasan data migrasi dan cutover

Sebelum sistem menjadi sumber utama, data backfill dapat memiliki urutan waktu berbeda: transaksi historis dapat masuk belakangan, atau catatan fisik lama tidak memiliki alokasi rak hasil scan di sistem ini. Karena itu data tersebut diberi penanda migrasi dan tidak boleh dipakai sebagai standar untuk membaca alur baru.

Setelah reset/cutover selesai, stok awal harus diimpor ke rak final, pesanan aktif direkonsiliasi menjadi cadangan yang benar, lalu stok channel baru diaktifkan. Dengan urutan tersebut, seluruh transaksi baru mengikuti satu alur: cadangkan saat pesanan aktif, potong fisik saat finish pick, dan kembalikan stok hanya ketika barang benar-benar kembali ke rak melalui proses yang sesuai.
