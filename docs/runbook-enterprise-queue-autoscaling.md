# Runbook: Enterprise Queue Autoscaling

## Tujuan

Menjaga jalur order, fulfillment, AWB, label, dan stok tetap cepat, sambil
mengurangi RAM idle pada pekerjaan background dan maintenance.

## Model kapasitas

- `order-intake`, `fulfillment`, `stock`, `labels-awb`, dan `labels-pdf` tetap
  memiliki satu pod Horizon hangat.
- `background` dan `maintenance` dapat turun ke nol replica ketika Redis queue
  kosong.
- KEDA menaikkan replica ketika list queue Redis memiliki pekerjaan siap proses.
- Horizon tetap mengatur concurrency proses di dalam setiap pod melalui
  `minProcesses`, `maxProcesses`, `maxJobs`, dan `maxTime`.

## Prasyarat satu kali di cluster

Jalankan dari mesin operator yang memiliki akses ke cluster production:

```bash
helm repo add kedacore https://kedacore.github.io/charts
helm repo update
helm upgrade --install keda kedacore/keda \
  --namespace keda \
  --create-namespace \
  --wait

kubectl wait --for=condition=Established \
  crd/scaledobjects.keda.sh \
  --timeout=120s
```

Manifest aplikasi tidak menyimpan password Redis. Cluster production saat ini
menggunakan Redis internal tanpa autentikasi; jika autentikasi Redis diaktifkan,
ubah trigger menjadi memakai `TriggerAuthentication` dan Secret Kubernetes,
bukan memasukkan password ke Git.

## Deployment

Jalankan pipeline production seperti biasa. Pipeline akan:

1. menerapkan deployment dasar;
2. mendeteksi `scaledobjects.keda.sh`;
3. menerapkan `08-keda-autoscaling.yaml` bila KEDA tersedia;
4. mencetak peringatan eksplisit bila KEDA belum tersedia.

Peringatan KEDA tidak membuat jalur kritis gagal deploy, tetapi berarti
penghematan RAM idle untuk background/maintenance belum aktif.

## Verifikasi setelah deploy

```bash
NS=cilupbah

kubectl get scaledobjects -n "$NS"
kubectl get hpa -n "$NS" | grep -E 'horizon|NAME'
kubectl describe scaledobject -n "$NS" cilupbah-horizon-background
kubectl describe scaledobject -n "$NS" cilupbah-horizon-maintenance

kubectl get deploy -n "$NS" \
  cilupbah-horizon \
  cilupbah-horizon-maintenance \
  cilupbah-horizon-order-intake \
  cilupbah-horizon-fulfillment \
  cilupbah-horizon-stock \
  cilupbah-horizon-labels-awb \
  cilupbah-horizon-labels \
  -o custom-columns='NAME:.metadata.name,DESIRED:.spec.replicas,READY:.status.readyReplicas'
```

Saat idle, `cilupbah-horizon` dan `cilupbah-horizon-maintenance` boleh berada di
`0` replica. Jalur kritis tidak boleh berada di `0`.

Cooldown maintenance sengaja panjang karena job cutover dapat berjalan sampai
30 menit. Ini mencegah scaler menghentikan pod hanya karena job aktif sudah
berpindah dari Redis `ready` ke `reserved`.

## Verifikasi antrean dan memory

```bash
kubectl exec -n "$NS" deploy/cilupbah-scheduler -- \
  php artisan channel:monitor-queue-health --json

kubectl top pod -n "$NS" --containers --sort-by=memory \
  | grep -E 'horizon|scheduler|app|pgbouncer'

kubectl get pods -n "$NS" \
  -o custom-columns='POD:.metadata.name,STATUS:.status.phase,RESTARTS:.status.containerStatuses[0].restartCount' \
  | grep -E 'horizon|scheduler|app|pgbouncer'
```

Kriteria penerimaan:

- tidak ada `OOMKilled` atau restart berulang;
- oldest ready job kritis berada di bawah SLO 5 menit;
- failed jobs tidak naik tanpa recovery;
- memory idle tidak meningkat terus selama 60 menit;
- PgBouncer tetap Ready dan tidak mengalami waiting client berkepanjangan;
- saat queue background menerima job, KEDA menaikkan replica;
- setelah queue kosong selama cooldown, replica background turun kembali.

## Rollback

```bash
kubectl delete scaledobject -n "$NS" \
  cilupbah-horizon-background \
  cilupbah-horizon-maintenance
```

Penghapusan ScaledObject tidak menghapus job Redis. Deployment dapat dikembalikan
ke replica statis melalui manifest aplikasi. Jalur kritis tidak bergantung pada
ScaledObject sehingga tetap berjalan selama rollback.
