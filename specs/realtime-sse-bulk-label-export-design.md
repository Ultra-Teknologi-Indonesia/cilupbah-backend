# Realtime SSE untuk Bulk Label dan Export

## Scope

Tahap pertama hanya mencakup:

- progres bulk tarik resi/label;
- progres export asynchronous.

REST tetap menjadi sumber kebenaran. SSE hanya mengirim notifikasi perubahan agar
frontend melakukan refresh snapshot yang terkontrol.

## Keputusan arsitektur

Gunakan satu Redis Stream per user, bukan satu koneksi atau channel per item.
Setiap entry memiliki `topic`, `event_type`, dan payload JSON. Reconnect memakai
`Last-Event-ID`; jika koneksi terputus, frontend dapat melanjutkan dari entry
terakhir tanpa kehilangan perubahan yang masih berada dalam retention window.

Frontend membagi satu koneksi untuk seluruh konsumen realtime aktif pada tab.
Endpoint menerima beberapa batch/export sekaligus, tetapi memverifikasi
ownership setiap id. Backend juga memakai semaphore terdistribusi berbasis Redis
dengan TTL agar jumlah koneksi SSE tidak dapat menghabiskan pool PHP-FPM.

Stream dibatasi durasi koneksinya. Ini penting karena aplikasi saat ini berjalan
di PHP-FPM; koneksi SSE tidak boleh memegang child FPM tanpa batas waktu.

## Alur

```text
Queue job / model update
        |
        v
Observer -> Redis Stream realtime:user:{user_id}
        ^
        |
Laravel SSE endpoint (auth + owner check + heartbeat)
        ^
        |
Next.js same-origin proxy (session cookie -> bearer token)
        ^
        |
Browser EventSource -> invalidate/refetch REST snapshot
```

## Security

- Endpoint tetap berada di bawah `auth:sanctum`.
- `batch_id` dan `export_id` harus dimiliki user aktif.
- User tidak dapat subscribe ke topic user lain.
- Payload hanya berisi status/progres, bukan token, credential, atau isi dokumen.
- Stream dibatasi rate dan retention-nya.

## Reliability

- EventSource melakukan reconnect otomatis.
- `Last-Event-ID` diteruskan pada reconnect manual.
- Event tidak menggantikan snapshot REST; reconnect selalu boleh melakukan refetch.
- Jika Redis/SSE gagal, frontend memakai fallback polling lambat.
- Heartbeat dikirim berkala agar proxy tidak menganggap koneksi idle.
- Jika kapasitas realtime penuh atau fitur sedang dimatikan, endpoint menolak
  koneksi secara terkontrol dengan `429`, bukan membiarkan worker menumpuk.

## Batasan tahap ini

Endpoint berjalan pada service API yang sama dengan PHP-FPM, tetapi stream
dibatasi waktunya dan dilindungi semaphore. Untuk traffic tinggi, tetap
disarankan menjalankan endpoint ini pada deployment/pool Laravel terpisah
dengan batas worker kecil; source code dan project Laravel tetap sama.
