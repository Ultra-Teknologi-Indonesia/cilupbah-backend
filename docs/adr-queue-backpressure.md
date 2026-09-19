# ADR: Backpressure untuk Pull Order dan Replay Webhook

Status: Accepted  
Tanggal: 2026-09-19

## Konteks

Scheduler order pull dan safety-net replay webhook dapat terus menambahkan job
ketika worker marketplace sedang lambat, Redis mendekati batas memori, atau
queue sedang tertahan. Menambah worker pada kondisi tersebut memperbesar risiko
OOM, duplikasi kerja, dan timeout berantai.

## Keputusan

- Pull order terjadwal memeriksa kedalaman queue `channel-sync` dan rasio memori
  Redis sebelum enqueue.
- Replay webhook memeriksa queue operasional sebelum enqueue.
- Jika kapasitas melewati batas, command berhenti dengan sukses tanpa menghapus
  data, mengubah status order, atau mengambil lease toko. Scheduler akan mencoba
  lagi pada siklus berikutnya.
- Jika kapasitas masih tersedia sebagian, enqueue dibatasi pada slot yang
  tersedia sehingga satu run tidak mengisi queue melewati batas.
- Pemeriksaan kapasitas bersifat fail-closed ketika Redis tidak dapat diperiksa.

## Batas production

- `channel-sync`: 24 job gabungan ready, delayed, dan reserved.
- Redis queue untuk pull order: maksimum 70% dari `maxmemory`.
- Queue operasional webhook untuk replay: 500 job gabungan.

Nilai dapat diubah melalui environment variables, tetapi tetap dijepit oleh
batas minimum/maksimum di `config/queue.php`.

## Konsekuensi

- Positif: mencegah backlog baru memperparah OOM dan menjaga recovery tetap
  idempoten.
- Positif: job yang belum masuk queue tidak hilang; window pull tetap akan
  diproses oleh run berikutnya.
- Negatif: saat queue penuh, order baru dapat menunggu satu atau beberapa
  siklus scheduler sampai kapasitas kembali tersedia.
- Operasional: pantau queue depth, oldest window, dan Redis memory sebelum
  menaikkan batas atau jumlah worker.
