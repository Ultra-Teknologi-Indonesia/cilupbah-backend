# Unduh label massal: kontrak, pengamanan, dan rollout

26 September 2026. Perubahan BE lokal; belum push, deploy, atau diuji beban di server. Melengkapi [runbook label](label-fast-path-rollout.md), bukan mengganti aturan bisnis pengiriman.

## Kontrak resmi

- [Shopee `v2.logistics.download_shipping_document`](https://open.shopee.com/documents/v2/v2.logistics.download_shipping_document?module=95&type=1): `POST /api/v2/logistics/download_shipping_document`, 1–50 baris `order_list`, **kurir yang sama**, setelah create document dan hasil pemeriksaan `READY`. Input baris `order_sn` dan `package_number` bila ada; tipe dokumen berada pada level request. Respons sukses file, gagal JSON. Batas 50 ini khusus endpoint dokumen ini, bukan batas universal semua endpoint shipping.
- [Lazada PrintAWB](https://open.lazada.com/apps/doc/api?path=/order/package/document/get): `getDocumentReq.packages` maksimal **20 package**, format PDF/HTML. Jalur massal baru menggunakan PDF dan kelompok per toko/kurir.
- TikTok tidak dialihkan ke endpoint Shopee. Jalur package, dokumen per-package, dan penggabungan seluruh URL yang sudah ada tetap dipertahankan. Batch ship tidak dianggap sebagai bukti API download label lintas order.

Dokumentasi Shopee tidak menjamin urutan halaman mengikuti urutan `order_list`. Implementasi tidak mengandalkan asumsi itu.

## Perubahan alur

1. Resi Shopee yang baru terbit masuk `waiting_shopee_prep`. Collector berjendela 2 detik mengumpulkan pekerjaan per toko, bukan langsung menjalankan unduhan per order.
2. Create/check dokumen tetap massal. Status belum siap diperiksa lewat delayed job 2, 3, 5, 8, 13, 30 detik, bukan worker tidur panjang. Respons create hilang/tidak pasti hanya diverifikasi; dokumen yang sudah berhasil tidak dibuat ulang.
3. Baris siap berlanjut tanpa menunggu baris lambat. Unduhan dikelompokkan per toko, kurir, dan tipe dokumen; maksimum Shopee 50 dan Lazada 20.
4. Setiap halaman PDF massal harus memiliki tepat satu identitas order/resi yang cocok. Semua order dalam kelompok harus terwakili. Ukuran halaman asli dipertahankan. File gambar saja, identitas ambigu, halaman kurang, PDF rusak, atau respons channel gagal memakai fallback per-order; tidak ada pemetaan berdasarkan posisi.
5. File terverifikasi masuk cache local-first yang sudah ada, lalu item job menggunakan cache untuk konversi/merge. Arsip ke object storage memakai outbox/worker arsip terdahulu. Retry menggunakan file valid tersimpan. R2 tetap harus dikonfigurasi pada disk arsip, bukan diasumsikan dari nama disk.
6. Label `READY` tidak lagi mengulang resolve/create/check pada jalur download. Jika Shopee secara eksplisit mengatakan `shipping_document_should_print_first`, hanya ledger **dokumen** dibuka untuk persiapan kembali; ledger permintaan resi tidak direset.
7. Pesanan split Shopee sampai 50 package diunduh dengan seluruh package. PDF dengan halaman kurang ditolak. Lebih dari 50 package **dalam satu order** belum didukung jalur baru dan menghasilkan pesan eksplisit, bukan mengambil paket pertama. Ini berbeda dari 150 order normal yang dibagi 50+50+50.

Tipe dokumen yang telah berhasil disimpan selama 6 jam per toko/kurir. Cache dibuang saat penolakan; penolakan create yang eksplisit dialihkan ke persiapan individual dengan parameter baru. Hasil create yang tidak pasti tetap hanya dipoll.

## Pengamanan dan resource

- Pengambilan file memakai lock per order bersama job individual. Persiapan memakai lock yang sama dengan job persiapan individual. Sebelum cache massal ditulis, pembatalan dan identitas shipment diperiksa kembali.
- Job massal hanya membawa ID, bukan bytes/base64. Batas input/output PDF massal 16 MiB, maksimum 100 halaman, dan pemeriksaan memori PHP sebelum parsing/import. PDF terlampau berat jatuh ke fallback.
- Ekstraksi teks maksimal 15 detik dan 2 MiB output. Pada Linux, proses `pdftotext` juga dibatasi address space 128 MiB dan CPU 10 detik. Docker sudah memasang `poppler-utils`. Batas native Linux perlu diverifikasi di image server; tes macOS tidak menjalankan `ulimit` Linux tersebut.
- Download job timeout 120 detik, supervisor download 180 detik, visibility/retry_after 240 detik secara default. Konfigurasi environment efektif tetap harus diaudit.
- Tidak menaikkan replika, worker, RAM limit, atau kuota marketplace. Audit kapasitas sekarang menghitung tambahan anggaran native PDF pada pool Shopee/Lazada.
- Limiter global Shopee tetap berlaku. Saat ada panggilan detail order/push stok/verifikasi model, panggilan nonkritis dibatasi setengah kuota selama sinyal aktivitas 2 detik. Ini reservasi sederhana berbasis aktivitas, **bukan** scheduler empat tingkat dengan jaminan fairness atau pembacaan backlog.
- Reaper didaftarkan sekali, tiap menit, ambang menunggu default 2 menit. Ini pemulihan cadangan, bukan pengganti notifikasi normal.
- Resi baru untuk instant/jenis kirim belum diketahui tidak diminta otomatis oleh batch. Label yang resinya sudah ada tetap boleh dicetak. Prefetch shipment tidak diaktifkan.

Batas-batas ini mengurangi risiko, bukan jaminan tidak pernah OOM. PHP parser, native renderer lain, database, Redis, spool, replica/surge dan node tetap harus diukur bersama.

## Hubungan dengan draf optimasi

| Saran | Kondisi setelah patch ini |
|---|---|
| L1 | Async sudah menjadi default kode; status deploy server belum diverifikasi. |
| L2–L5 | Reaper, transisi AWB, cache tipe, collector/poll/download massal ditangani. Fallback aman tetap dapat menambah panggilan. |
| L6 | Preflight TikTok massal/reuse package dari implementasi terdahulu dipertahankan. |
| L7 | Cetak yang siap sudah tersedia. Belum otomatis hanya mencetak bagian yang belum pernah dicetak. |
| L8 | Tidak menaikkan prefetch AWB: berisiko memesan kurir lebih awal, bertentangan dengan kebutuhan operasional. |
| L9 | Batch tidak meminta AWB untuk instant atau jenis kirim belum diketahui. |
| O2 | Reservasi kuota sederhana, tidak menaikkan batas resmi dan belum seluruh hierarki prioritas dalam draf. |
| T1–T3, O1 | Tidak diimplementasikan oleh patch download ini. Verifikasi mapping live, concurrency stok, urutan prioritas stok dan jalur webhook tidak dilonggarkan. Micro-batch webhook memerlukan pekerjaan dan pengujian terpisah. |

Jadi **bukan seluruh draf sudah selesai**. Khusus cache mapping live, hasil yang lama berpotensi mengirim stok ke varian salah bila seller mengubah mapping di channel; pengaman fail-closed tidak dihapus demi angka kecepatan.

## Rollout dan rollback

Deploy image yang sama ke app, scheduler dan seluruh worker dahulu. Jangan mengganti prefix/host Redis atau menghapus job. Prasyarat PVC shared spool dan migrasi cache/outbox terdahulu tetap berlaku; patch ini tidak menambah migrasi.

Konfigurasi dalam sumber deployment:

```dotenv
LABEL_ASYNC_SHOPEE_PREPARATION=true
LABEL_MASS_DOWNLOAD_ENABLED=true
LABEL_MARKETPLACE_WAIT_RECOVERY_MINUTES=2
SHOPEE_CRITICAL_API_PRIORITY=true
```

Untuk mematikan optimasi unduhan, set `LABEL_MASS_DOWNLOAD_ENABLED=false` melalui rollout biasa. Job yang terlanjur antre akan memakai fallback. Flag tidak menghapus cache dan tidak mengubah shipment. `SHOPEE_CRITICAL_API_PRIORITY=false` kembali ke limiter global terdahulu tanpa menaikkan kuota. Jangan downgrade image selama job kelas baru masih antre; flag lebih aman daripada rollback kode yang tidak mengenali job.

Audit read-only dari host server Kubernetes:

```bash
kubectl -n cilupbah --request-timeout=30s rollout status deploy/cilupbah-horizon-labels --timeout=60s
kubectl -n cilupbah --request-timeout=30s exec deploy/cilupbah-horizon-labels -- php artisan channel:audit-label-capacity --json
kubectl -n cilupbah --request-timeout=30s exec deploy/cilupbah-horizon-labels -- pdftotext -v
kubectl --request-timeout=30s top nodes
kubectl -n cilupbah --request-timeout=30s top pods --containers
kubectl -n cilupbah --request-timeout=30s exec deploy/cilupbah-app -- php artisan shipping-labels:reconcile-cache --status
```

Command di atas bukan load test dan tidak memanggil channel. Pastikan `mass_label_download=true`, antrean `label-download-shopee`/`lazada` memiliki consumer, disk arsip benar R2, dan anggaran pod aktual cocok dengan audit.

## Penerimaan dan batas estimasi

Uji lokal memakai DB testing, HTTP stub dan PDF sintetis. Skenario 150 order Shopee siap, satu toko/kurir, membuktikan 3 panggilan unduhan dan reuse saat retry; **bukan** bukti 150 order cold selesai dalam beberapa detik. Pengujian juga mencakup urutan halaman terbalik, identitas ambigu, campuran kurir, pembatalan saat download, dokumen kedaluwarsa, split package, kegagalan API dan Lazada 20 order.

Di staging, ukur request count, waktu antre, waktu API, konversi/merge, klik→label pertama dan klik→semua siap; p50/p95/p99, fallback rate, 429, RSS, restart, disk dan umur antrean order/stok. Bandingkan flag mati/aktif dengan komposisi yang sama. Jangan load-test channel production atau menyatakan SLA hitungan detik sebelum hasil tersedia.

Jika label sudah terbit, jumlah panggilan yang jauh berkurang membuka peluang selesai dalam detik. Jika resi/dokumen belum diterbitkan, waktu menunggu marketplace tetap tidak bisa dijamin. Print sebagian tetap berguna agar order lambat tidak menahan yang siap.
