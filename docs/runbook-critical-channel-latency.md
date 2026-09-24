# Prioritas pesanan, stok, dan resi/label

## Status dan batas klaim

Perbaikan ini menghilangkan penundaan dan kehilangan wake-up yang dapat
direproduksi pada kode. Tes lokal bukan bukti kapasitas production atau SLA
marketplace. Belum ada hasil load test untuk menjamin seluruh proses selesai
dalam detik pada flash sale. Host `temet01bare127` tidak dapat di-resolve dari
lingkungan pengembangan saat pemeriksaan ini.

## Perubahan yang harus ikut image deployment

1. Pembaruan order menerima satu pembaruan lanjutan ketika event baru masuk
   selama pembacaan API sebelumnya masih berjalan. Job pending tetap dideduplikasi;
   middleware pengunci order tetap mencegah penulisan bersamaan.
2. Webhook tertahan kapasitas dijadwalkan ulang setelah 10 detik secara default,
   tanpa backoff eksponensial sampai satu jam dan tanpa menunggu usia dua menit.
   Lease job yang sudah masuk queue, cutoff event historis, pause admin, dan
   pembatasan memori tetap dihormati. Scheduler memeriksa setiap 10 detik di
   background; ini interval pemeriksaan, bukan janji waktu selesai.
3. Kapasitas replay dihitung per queue tujuan. Antrean katalog/fulfillment yang
   penuh tidak menghentikan seluruh lane. Jumlah dispatch pada satu run ikut
   mengurangi anggaran snapshot agar cache kapasitas tidak dibelanjakan berulang.
   Retry diurutkan berdasarkan jatuh tempo sebelum waktu penerimaan agar lane
   yang terus penuh tidak selalu menempati halaman pertama.
4. Outbox stok membangunkan dispatcher ketika versi lama digantikan versi baru.
   Toko yang sudah mencapai batas in-flight dikeluarkan sebelum batas kandidat
   diterapkan. Scheduler dan wake job memakai pengunci dispatcher bersama.
   Pengaman scheduler berjalan setiap 5 detik; versi stok, batas in-flight dan
   penanganan kegagalan API tetap berlaku.
5. Item label berstatus menunggu sebelum persiapan dibangunkan, lalu status
   order dicek kembali. Pemberitahuan siap yang sangat cepat tidak tertinggal.
   Deduplikasi job download berakhir saat mulai diproses agar pemberitahuan siap
   dapat menjadwalkan kelanjutan; lock eksekusi per order tetap digunakan.
6. Ketika limiter lokal download label menolak slot, retry mengikuti panjang
   jendela limiter (default 1 detik), bukan tambahan tetap 10 detik. Ini tidak
   mengubah batas API atau memaksa channel menghasilkan dokumen lebih cepat.

Perbaikan boolean PostgreSQL dari commit `27a7d133` harus ikut terdeploy. Log
`boolean = integer` menunjukkan query ditolak database; menambah worker saja
tidak akan menyelesaikan kegagalan penyimpanan pesanan tersebut.

## Audit setelah deployment

Jalankan di server. Perintah berikut membaca status dan mencetak snapshot/log;
tidak melakukan replay massal, menghapus job, atau mengubah jumlah worker.

```bash
kubectl -n cilupbah get deployments
kubectl -n cilupbah get pods -o wide
kubectl -n cilupbah top pods --containers
kubectl -n cilupbah get events --sort-by=.lastTimestamp
kubectl -n cilupbah exec deploy/cilupbah-horizon-order-intake -- php artisan channel:monitor-queue-health --json
kubectl -n cilupbah exec deploy/cilupbah-horizon-stock -- php artisan channel:monitor-stock-outbox --json
kubectl -n cilupbah logs deploy/cilupbah-horizon-order-intake --since=10m --tail=200
kubectl -n cilupbah logs deploy/cilupbah-horizon-stock --since=10m --tail=200
kubectl -n cilupbah logs deploy/cilupbah-horizon-labels-awb --since=10m --tail=200
kubectl -n cilupbah logs deploy/cilupbah-horizon-labels --since=10m --tail=200
```

Pastikan image app, scheduler, dan seluruh worker berasal dari revisi yang sama.
Scheduler perlu direstart lewat rollout agar jadwal sub-menit baru dimuat.
Jangan memutar ulang seluruh event lama: hormati `WEBHOOK_REPLAY_AFTER`, identifikasi
pesanan yang terdampak insiden aktif, dan pulihkan secara terbatas setelah akar
error hilang. Snapshot antrean kosong tidak membuktikan tidak ada order hilang;
bandingkan daftar/status order channel dengan data internal.

## Kriteria penerimaan pada beban representatif

Ukur terpisah waktu antre, waktu kerja internal, dan waktu tunggu channel.
Gunakan p95/p99 dan umur pekerjaan tertua, bukan hanya rata-rata harian.

| Alur | Bukti penerimaan |
| --- | --- |
| Pesanan | Semua order uji ditemukan; paid/cancel sama dengan channel; event duplikat dan event saat refresh berlangsung tidak menghilangkan status terakhir. |
| Stok | Angka channel akhirnya sesuai versi terbaru; versi lama tidak menang; satu toko yang melambat tidak menghentikan toko lain; umur pending turun setelah lonjakan selesai. |
| Resi/label | Ukur batch 1, 20, 100, dan 200 terpisah; tutup/buka modal dan klik ulang tidak menggandakan operasi channel; jumlah/isi halaman PDF sesuai order dan paket. |
| Kapasitas | Tidak ada OOMKilled/eviction; koneksi DB/Redis dan memori punya ruang; backlog dapat dikuras pada laju masuk yang disepakati. |
| Kegagalan | Timeout, 429, Redis sementara tidak tersedia, restart worker, dan rollout menghasilkan retry/pemulihan yang tercatat tanpa kehilangan pekerjaan. |

Target “detik” perlu disepakati untuk ukuran batch dan keadaan channel tertentu.
Label yang belum disediakan channel atau channel yang membalas 429 tidak bisa
dijamin selesai dalam waktu tetap. Push stok cepat mengurangi risiko oversell,
tetapi tidak membuat transaksi lintas marketplace menjadi atomik. Perubahan
jumlah worker harus didasarkan pada laju masuk puncak, waktu API, anggaran
koneksi/memori, dan kuota per toko yang terukur.
