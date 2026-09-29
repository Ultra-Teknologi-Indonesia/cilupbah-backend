# Runbook Server dan Deployment

## 1. Gambaran arsitektur produksi

Deployment production menggunakan Kubernetes/k3s pada namespace `cilupbah`,
dengan komponen utama:

- `cilupbah-app`: HTTP API.
- Horizon profile terpisah untuk order intake, stock, fulfillment, critical, labels, maintenance, dan worker background/export.
- Redis terpisah dapat digunakan untuk default/cache/finance/horizon/long queue.
  Pada audit terakhir hanya `cilupbah-redis` yang aktif; empat deployment Redis
  lain berada pada `0/0`. Ini harus dicocokkan dengan konfigurasi queue sebelum
  BAST agar queue tidak menunjuk ke service yang sengaja dimatikan.
- PostgreSQL melalui PgBouncer.
- Gotenberg dan worker PDF untuk dokumen.
- Scheduler dan job migration.
- Ingress/domain sesuai konfigurasi server client.

Manifest resmi berada di `cilupbah-be/k8s/production/`. Jangan mengubah pod
langsung sebagai cara deployment normal.

## 2. Identitas deployment dan status audit

Snapshot berikut berasal dari audit cepat namespace `cilupbah` pada 28 September
2026 pukul 13:09 WIB. Audit dijalankan ketika rollout masih berlangsung; angka
dan status pod bukan bukti kondisi final setelah rollout.

```text
Namespace: cilupbah
Cluster/context: default (k3s, temet01bare127.neometal.id)
Node: `temet01bare127.neometal.id`, k3s `v1.36.4+k3s1`, sekitar 32 GiB allocatable
Production image/tag: `ghcr.io/ultra-teknologi-indonesia/cilupbah:v1.0.849`
Production image digest: `sha256:d1b4004c096cdaa985b1740a68c09a3cc103294ec31e27804c72ea1c6e5a9d8b`
Release commit yang tercatat pada audit: `c35e6a4d8ebdf70a2b1efbd8b8d80fd040a926b6`
Staging image/commit: belum diambil dari environment staging; jangan disamakan dengan production.
API base URL: https://be-superapp.ultra-fit.id
Web base URL: https://app.ultra-fit.id
Staging API base URL: https://dev-backend-app.ultra-fit.id
Staging web base URL: https://dev-frontend-app.ultra-fit.id
Swagger URL: https://be-superapp.ultra-fit.id/api/documentation
Database backup location: belum tercatat; pemilik infrastruktur wajib mencatat lokasi, retensi, enkripsi, dan prosedur restore.
Object storage/R2 bucket: R2 aktif (bucket dan endpoint terkonfigurasi); nama bucket tidak dicantumkan untuk menjaga kerahasiaan konfigurasi.
Monitoring/Sentry: belum tercatat pada audit; catat URL project, alert aktif, dan pemilik akses.
On-call contact: belum tercatat; catat nama, kanal, dan jam eskalasi.
```

Secret value tidak ditulis di dokumen ini. Catat nama secret, pemilik akses, dan
lokasi password manager secara terpisah. Audit cepat mencatat node Ready dengan
penggunaan sekitar 10% CPU dan 41% memori. Perkiraan baris database saat
snapshot: `channel_catalog_sku_indexes=32085`, `channel_webhook_inbox=638`,
`failed_jobs=191`, dan `product_sync_logs=3601`.
Redis utama memakai sekitar 38 MB dari batas 3 GB, AOF aktif, dan status write
terakhir OK. Empat deployment Redis khusus terlihat `0/0`; ini harus dicocokkan
dengan konfigurasi queue, bukan langsung dianggap rusak.

Audit juga menemukan pod lama `Terminating` (maintenance sekitar 20 jam dan
order-intake sekitar 10 menit) serta beberapa worker yang restart satu kali.
Penyebab restart belum dapat disimpulkan dari audit cepat.

### Temuan domain dan TLS yang wajib diverifikasi setelah rollout

Pada saat audit rollout, Ingress namespace `cilupbah` masih menampilkan host
`backend.ultra-fit.id`. Karena deployment belum selesai, ini belum dapat
disimpulkan sebagai konfigurasi final. Setelah rollout, staging harus mengarah
ke `dev-backend-app.ultra-fit.id`, sedangkan production harus mengarah ke
`be-superapp.ultra-fit.id`.

Audit sebelumnya juga tidak menemukan secret TLS `cilupbah-tl` dan CRD
cert-manager. Hal tersebut perlu diverifikasi ulang pada environment yang benar;
status edge Cloudflare tidak otomatis membuktikan TLS origin Ingress.

Callback yang dikonfirmasi client:

- Shopee OAuth: `https://be-superapp.ultra-fit.id/api/v1/shopee/callback`;
  Live Redirect URL Domain: `https://be-superapp.ultra-fit.id/`.
- TikTok Shop OAuth: `https://be-superapp.ultra-fit.id/api/v1/tiktok/callback`;
  webhook: `https://be-superapp.ultra-fit.id/api/v1/tiktok/webhook`.
- Lazada OAuth: `https://be-superapp.ultra-fit.id/api/v1/lazada/callback`.
  Authorized Seller Whitelist harus diisi di console Lazada sebelum connect.

URL tersebut adalah catatan konfigurasi marketplace, bukan bukti bahwa Ingress
origin sudah benar. Buktikan dari cluster dengan perintah berikut sebelum BAST:

```bash
NS=cilupbah
kubectl -n "$NS" get ingress -o yaml
kubectl -n "$NS" get secret cilupbah-tl -o jsonpath='{.type}{"\n"}'
kubectl -n "$NS" get svc,pods -o wide
kubectl -n "$NS" get endpointslice -l kubernetes.io/service-name=cilupbah-app -o wide
```

Untuk production, nilai host Ingress harus `be-superapp.ultra-fit.id`; untuk
staging gunakan `dev-backend-app.ultra-fit.id`. Secret TLS harus bertipe
`kubernetes.io/tls`, sertifikat harus memuat `be-superapp.ultra-fit.id`, dan
endpoint service harus memiliki pod ready. Jika salah satu tidak terpenuhi,
status Go-Live tetap **belum terverifikasi**. Untuk handover as-is, catat hasil
aktual dan pemilik tindak lanjutnya.

## 3. Preflight read-only

```bash
NS=cilupbah
kubectl config current-context
kubectl -n "$NS" get deploy,pods -o wide
kubectl -n "$NS" get events --sort-by=.lastTimestamp | tail -50
kubectl top node
kubectl -n "$NS" top pods --containers
kubectl -n "$NS" get pvc
```

Cari kondisi berikut sebelum deploy:

- pod `Pending`, `CrashLoopBackOff`, `OOMKilled`, atau `Terminating` terlalu lama;
- memory request node mendekati kapasitas;
- migration job gagal;
- Redis/PostgreSQL/PgBouncer tidak ready;
- queue age atau failed jobs meningkat.

Pastikan seluruh deployment production menggunakan tag atau digest image yang
konsisten. Image lokal yang berbeda dari image release harus dijelaskan di
catatan rollout sebelum handover.

## 4. Jalur deployment yang disarankan

1. Merge kode ke branch deployment sesuai kebijakan repository.
2. CI membangun dan push image bertag immutable ke GHCR.
3. CI membuat job migration terpisah dan menunggu `Complete`.
4. Jika migration gagal, rollout dihentikan.
5. CI memperbarui image deployment dan menunggu readiness.
6. Jalankan post-deploy smoke test dan simpan bukti commit/image/pod.

Migration tidak dijalankan sembarang di setiap container start. Gunakan workflow
CI/CD atau job migration resmi agar hanya satu migration runner yang aktif.

## 5. Verifikasi pasca-deploy

```bash
NS=cilupbah
kubectl -n "$NS" rollout status deploy/cilupbah-app --timeout=15m
kubectl -n "$NS" get deploy -o custom-columns=NAME:.metadata.name,READY:.status.readyReplicas,UPDATED:.status.updatedReplicas
kubectl -n "$NS" get pods -o wide
kubectl -n "$NS" get jobs --sort-by=.metadata.creationTimestamp | tail -20
kubectl -n "$NS" get pods -o json | jq -r '
  .items[] | .status.containerStatuses[]? |
  select(.restartCount > 0 or .lastState.terminated.reason == "OOMKilled") |
  [.name, .restartCount, (.lastState.terminated.reason // "-")] | @tsv'
```

Verifikasi aplikasi melalui endpoint health/readiness yang resmi. Jangan memakai
perintah yang menampilkan secret atau payload webhook.

## 6. Queue dan Horizon

```bash
kubectl -n "$NS" exec deploy/cilupbah-horizon -- php artisan horizon:status
kubectl -n "$NS" exec deploy/cilupbah-app -c app -- php artisan tinker --execute='echo DB::table("failed_jobs")->count().PHP_EOL;'
kubectl -n "$NS" logs deploy/cilupbah-horizon-order-intake --tail=200
kubectl -n "$NS" logs deploy/cilupbah-horizon-stock --tail=200
```

Pantau ready, delayed, reserved, oldest age, failed jobs, retry count, dan
throughput. Queue critical order/stock tidak boleh dikorbankan untuk export atau
PDF. Jangan menjalankan `queue:retry all` tanpa mengelompokkan penyebab dan
memastikan job idempotent.

## 7. Database, Redis, dan storage

- PostgreSQL: cek koneksi aktif, lock waiter, pool PgBouncer, ruang disk, dan backup terakhir.
- Redis: cek memory, persistence, queue depth, eviction, dan ruang PVC.
- R2/object storage: pastikan upload/download hasil laporan/label dapat diakses oleh aplikasi dan lifecycle retention berjalan.
- Backup: lakukan restore test berkala pada environment terpisah; backup yang belum pernah direstore belum dianggap tervalidasi.

Contoh pemeriksaan non-destruktif:

```bash
kubectl -n "$NS" get pvc
kubectl -n "$NS" exec deploy/cilupbah-redis -- redis-cli INFO memory
kubectl -n "$NS" exec deploy/cilupbah-redis -- redis-cli INFO persistence
df -h
```

## 8. Rollback

Rollback hanya ke image/tag yang sudah diketahui sehat dan disepakati:

```bash
NS=cilupbah
DEPLOY=cilupbah-app
kubectl -n "$NS" rollout history deploy/"$DEPLOY"
kubectl -n "$NS" rollout undo deploy/"$DEPLOY"
kubectl -n "$NS" rollout status deploy/"$DEPLOY" --timeout=15m
```

Untuk rollback migration yang mengubah data/schema, jangan menjalankan reverse
migration otomatis. Tinjau migration dan backup terlebih dahulu.

## 9. Insiden umum

### `Insufficient memory`

Hentikan simulasi/export non-kritis, cek resource requests, pod lama, dan node
headroom. Jangan menghapus pod production secara paksa sebelum memastikan pod
pengganti ready dan pekerjaan aman diulang.

### `OOMKilled` atau exit 137

Ambil log sebelumnya, identifikasi job/worker, cek memory usage dan payload besar,
lalu batasi concurrency atau restart lifecycle secara aman. Jangan sekadar
menaikkan limit tanpa menghitung kapasitas node.

### Queue tua atau terus bertambah

Bandingkan arrival rate dengan completion rate. Pisahkan error upstream, rate
limit, job duplikat, dan kapasitas worker. Pastikan retry memiliki backoff dan
idempotency key.

### Shopee `Partner and shop has no linked`

Ini berarti relasi toko dengan partner/aplikasi Shopee belum aktif. Dampaknya
dapat terlihat sebagai kegagalan finance dan sinkronisasi retur karena keduanya
memerlukan refresh token. Hubungkan ulang toko pada portal/integrasi resmi,
pastikan callback OAuth production benar, lalu uji satu job finance dan satu
tracking retur sebelum melakukan retry terbatas. Jangan mengatasi masalah ini
dengan retry massal atau truncate `failed_jobs`.

### Pod lama `Terminating`

Periksa termination grace, proses yang belum menerima SIGTERM, dan job yang masih
berjalan. Tunggu graceful shutdown bila masih memproses pekerjaan; force delete
hanya setelah ada keputusan operasional dan bukti tidak ada data yang sedang ditulis.

## 10. Batasan yang wajib dicatat di BAST

- Satu node Kubernetes adalah single point of failure untuk kapasitas node.
- API marketplace memiliki rate limit dan proses async yang berada di luar kendali aplikasi.
- Webhook/callback harus diarahkan ke domain production baru; perubahan DNS saja
  tidak mengganti konfigurasi callback di marketplace.
- Hasil load test sintetis tidak sama dengan sertifikasi traffic production.
- Target waktu harus dibedakan antara menerima webhook, mencatat order, terbitnya AWB oleh channel, download dokumen, dan merge PDF.
- Penambahan kapasitas, provider, atau perubahan business logic adalah pekerjaan terpisah/Change Request bila di luar scope.
