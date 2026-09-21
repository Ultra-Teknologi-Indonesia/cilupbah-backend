# ADR: Jalur Cepat Sinkronisasi Stok Marketplace

## Status

Accepted

## Konteks

Satu perubahan stok internal dapat memengaruhi banyak listing marketplace. Jalur lama menaruh pekerjaan ke beberapa antrean sebelum masuk ke outbox stok, sedangkan outbox baru terutama dibangunkan oleh scheduler. Akibatnya, perubahan lama dapat memenuhi antrean walaupun sudah digantikan oleh angka stok yang lebih baru.

## Keputusan

- Webhook order tetap diproses secara idempoten dan refresh detail order tidak lagi menunggu jeda buatan untuk event order.
- Setelah perubahan stok lokal berhasil di-commit, outbox stok langsung dibangunkan melalui dispatcher kecil yang unik.
- Dispatcher outbox dipisahkan dari antrean stok normal. Dispatcher hanya mengambil pekerjaan yang sudah due; panggilan API marketplace tetap dilakukan oleh job stok yang sudah memiliki lane critical/normal.
- Satu baris outbox tetap menjadi satu listing. Perubahan berikutnya menaikkan versi dan menggantikan pekerjaan lama, sehingga satu SKU dengan banyak listing tidak menggandakan pengiriman untuk nilai stok yang sama.
- Batas pekerjaan tetap berdasarkan toko: satu pengiriman aktif per toko secara default, dan pacing per channel tetap berlaku. Nilainya dapat dinaikkan menjadi dua melalui environment variable setelah kapasitas server dan rate limit diaudit.
- Scheduler outbox dipertahankan sebagai jaring pengaman, tetapi frekuensinya diturunkan karena jalur normal sudah dibangunkan langsung.

## Dampak

Perubahan stok lebih cepat terlihat karena tidak menunggu scheduler atau antrean job produk tambahan. Queue dispatcher hanya membawa pekerjaan kecil dan unik, sehingga tidak membuat lonjakan CPU atau memori. Lock database, versi outbox, dan lease tetap menjadi pengaman race condition. Coalescing mencegah backlog untuk SKU yang berubah berkali-kali; yang dikirim adalah nilai stok terbaru.

## Batasan

Marketplace tetap memiliki rate limit, latency, dan error eksternal. Sistem tidak memaksa retry cepat untuk error permanen. Error sementara tetap masuk retry/backoff dan outbox yang durable akan dipulihkan oleh scheduler.
