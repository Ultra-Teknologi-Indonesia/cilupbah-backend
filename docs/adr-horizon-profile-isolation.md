# ADR: Profil Horizon Terpisah untuk Antrean Critical dan Background

Status: Accepted
Tanggal: 2026-09-14

## Keputusan

Horizon production dijalankan sebagai dua Deployment dengan queue yang tidak
overlap:

- `critical`: order, webhook operasional, tracking, fulfillment, dan stok;
- `background`: katalog, finance, after-sales, export/download, label, dan
  pekerjaan cutover.

Profil critical memiliki worker minimum yang tetap hangat dan autoscaling
berdasarkan ukuran antrean. Batas maksimum tetap eksplisit per supervisor,
`balanceMaxShift=1`, dan cooldown pendek agar burst naik bertahap tanpa
lonjakan CPU/RAM.

Pod API memiliki HPA yang dibatasi 2--3 replika berdasarkan CPU dan RAM, dengan
scale-down yang ditunda. Dengan demikian endpoint webhook tetap dapat menerima
burst tanpa menjadikan worker queue sebagai proses HTTP sinkron.

## Batas keselamatan

Setiap profil mempunyai ceiling worker+master sekitar 4 GiB dan pod limit 5
GiB. Nilai `memory` Horizon tetap menjadi batas daur ulang worker; pod limit
menjadi pagar terakhir terhadap OOM. Worker juga didaur ulang dengan
`maxJobs`/`maxTime` yang sudah ada.

Dua profil memakai `Recreate`, bukan menjalankan dua master critical secara
bersamaan. Queue Redis tetap persisten sehingga pekerjaan tidak hilang saat
rollout, sementara risiko side effect ganda dari job non-idempotent tidak
ditambah.

## Dampak operasional

Burst event marketplace tidak lagi menunggu katalog/export. Jika kapasitas
critical penuh, antrean tetap bertambah secara terukur dan dapat diawasi
melalui panjang antrean, queue wait, failed jobs, throttling CPU, dan memory
events. Konfigurasi ini mengurangi risiko OOM/5xx; angka nol absolut tetap
tidak dapat dijanjikan tanpa load test dan kapasitas marketplace/API yang
memadai.

## Rollout dan verifikasi

1. Deploy image dan dua manifest Horizon.
2. Pastikan kedua pod `Ready` dan masing-masing memuat profil yang benar.
3. Pastikan setiap queue dilayani tepat satu profil dan tidak ada queue yang
   tertinggal.
4. Pantau pending queue, oldest job age, failed jobs, Redis memory/eviction,
   cgroup `oom_kill`, dan CPU throttling sebelum menaikkan batas lagi.
