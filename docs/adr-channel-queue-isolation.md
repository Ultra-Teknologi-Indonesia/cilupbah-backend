# ADR: Isolasi Antrean Sinkronisasi Channel

Status: Accepted  
Tanggal: 2026-08-28

## Keputusan

Pekerjaan sinkronisasi channel dipisahkan berdasarkan dampaknya terhadap operasi:

- \`channel-cancellation\`: keputusan terima/tolak pembatalan ke marketplace;
- \`channel-stock\`: pemicu sinkronisasi stok;
- \`channel-product\`: sinkronisasi katalog, harga, stok produk, dan atribut channel;
- \`channel-finance\`: sinkronisasi settlement/keuangan pesanan;
- \`channel-after-sales\`: retur dan refund marketplace;
- \`channel-fulfillment\`: pengiriman, pemanggilan driver, dan fulfillment Lazada.

Antrean \`channel-sync\` lama tetap dilayani selama masa pengosongan. Tidak ada penghapusan,
pemindahan paksa, atau pembersihan queue yang dapat menghilangkan pekerjaan yang sudah masuk.

## Alasan

Sebelumnya pekerjaan yang cepat dan kritis bercampur dengan pekerjaan katalog yang bisa lambat.
Akibatnya keputusan pembatalan dapat menunggu pekerjaan lain dalam antrean yang sama.
Pemisahan membuat pembatalan memiliki jalur khusus dan membuat beban besar tidak menahan
operasi pesanan.

## Perlindungan memori dan duplikasi

- Worker baru memakai satu proses per fungsi dan didaur ulang berdasarkan jumlah job/waktu.
- Pekerjaan produk yang dapat memakan waktu memakai koneksi \`redis-long\` dengan \`retry_after\`
  lebih panjang daripada timeout job, sehingga tidak dikerjakan ganda oleh worker lain.
- Resinkronisasi toko menggunakan \`chunkById(500)\` dan tidak mengambil seluruh daftar produk
  ke memori sekaligus.
- Respons pembatalan memakai kunci unik per pesanan dan keputusan serta \`WithoutOverlapping\`
  untuk mencegah pemrosesan bersamaan.
- Job yang gagal tetap masuk mekanisme failed jobs dan dicatat dengan konteks pesanan; tidak
  dianggap berhasil secara diam-diam.

## Operasional rilis

Setelah rilis kode dan konfigurasi, worker Horizon harus dimuat ulang secara bertahap.
Queue lama harus dipantau sampai kosong sebelum supervisor legacy dihentikan. Jumlah job,
failed jobs, waktu tunggu, dan penggunaan memori harus diperiksa sebelum menaikkan paralelisme.
