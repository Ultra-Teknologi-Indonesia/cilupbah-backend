# Runbook: Adaptive Production Queue Capacity

## Tujuan

Menjalankan semua proses dalam satu mode otomatis. Order, stok, resi, label,
background, maintenance, import, dan export selalu memiliki worker aktif.
Kapasitas bertambah ketika antrean naik dan kembali ke batas minimum setelah
stabil tanpa menghentikan jenis pekerjaan apa pun.

## Model produksi

| Pool | Minimum | Maksimum | Pemicu tambahan |
|---|---:|---:|---|
| App API | 3 | 4 | CPU dan memory |
| Order intake | 1 | 2 | Queue order dan refresh |
| Fulfillment | 1 | 2 | Queue fulfillment |
| Stock | 1 | 2 | Queue stok kritis dan outbox |
| Marketplace operations | 1 | 2 | Webhook, cancellation, tracking |
| Background | 1 | 2 | Webhook umum, produk, finance |
| AWB | 1 | 1 | Horizon process scaling |
| Label | 1 | 1 | Horizon process scaling |
| Maintenance | 1 | 1 | Selalu tersedia |
| Import/export | 1 per deployment | 1 | Selalu tersedia |

Total request memory pada seluruh batas maksimum tidak boleh melebihi 25,600
MiB. Batas tersebut menyisakan dua puluh persen kapasitas node untuk sistem
operasi, Kubernetes, lonjakan proses, dan stabilitas.

## Prasyarat cluster

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

## Deployment

Pipeline production menjalankan migrasi lebih dahulu, memeriksa kapasitas node,
kemudian me-rollout setiap workload secara berurutan. Pipeline berhenti sebelum
rollout berikutnya jika request memory plus kebutuhan surge melewati sembilan
puluh persen kapasitas atau jika ada pod yang dinyatakan `Unschedulable`.
Setelah replica baru siap, pipeline menunggu pod lama menyelesaikan pekerjaannya
dan benar-benar melepaskan reservasi resource sebelum memulai surge berikutnya.
Pod `Pending` singkat yang belum dinyatakan `Unschedulable` tidak menggagalkan
rollout.

KEDA wajib tersedia. Seluruh ScaledObject harus mencapai kondisi `Ready` agar
deployment dinyatakan berhasil.

## Verifikasi

```bash
NS=cilupbah

kubectl get scaledobjects -n "$NS"
kubectl get hpa -n "$NS"
kubectl get deploy -n "$NS" \
  -o custom-columns='NAME:.metadata.name,DESIRED:.spec.replicas,READY:.status.readyReplicas'

kubectl exec -n "$NS" deploy/cilupbah-scheduler -- \
  php artisan channel:monitor-queue-health --json

kubectl exec -n "$NS" deploy/cilupbah-scheduler -- \
  php artisan channel:monitor-stock-outbox --json

kubectl top node
kubectl top pod -n "$NS" --containers --sort-by=memory

kubectl get pods -n "$NS" \
  -o custom-columns='POD:.metadata.name,STATUS:.status.phase,RESTARTS:.status.containerStatuses[0].restartCount'
```

Kriteria penerimaan:

- seluruh deployment memiliki replica tersedia sesuai minimum;
- tidak ada pod `Pending`, `OOMKilled`, atau restart berulang;
- antrean kritis bertambah saat burst lalu turun kembali;
- oldest ready job tidak terus naik;
- failed job baru tidak bertambah tanpa recovery;
- Redis tidak melakukan eviction;
- PgBouncer tidak memiliki waiting client berkepanjangan;
- throughput order, stok, AWB, dan label tetap bergerak bersamaan;
- deployment tidak pernah menurunkan worker aktif ke nol.

## Perubahan kapasitas

Replica maksimum, process maksimum Horizon, memory request, dan marketplace rate
limit tidak boleh dinaikkan terpisah. Setiap perubahan harus memperbarui tes
kapasitas Kubernetes, lulus simulasi terisolasi, kemudian diverifikasi terhadap
CPU, memory, queue age, failed jobs, Redis, PostgreSQL, dan PgBouncer.

## Rollback

Rollback menggunakan manifest versi terakhir yang lulus. Jangan menghapus
ScaledObject ketika antrean masih aktif. Jika controller KEDA bermasalah,
pertahankan seluruh deployment pada satu replica sampai controller pulih agar
tidak ada jenis pekerjaan yang berhenti.
