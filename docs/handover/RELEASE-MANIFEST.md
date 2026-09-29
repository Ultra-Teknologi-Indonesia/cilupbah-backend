# Release Manifest

## Production snapshot

| Item | Nilai | Sumber/status |
|---|---|---|
| Namespace | `cilupbah` | Audit cepat 28 Sep 2026 |
| Backend image | `ghcr.io/ultra-teknologi-indonesia/cilupbah:v1.0.849` | Tercatat pada deployment |
| Backend image digest | `sha256:d1b4004c096cdaa985b1740a68c09a3cc103294ec31e27804c72ea1c6e5a9d8b` | Tercatat pada pod aktif |
| Release commit | `c35e6a4d8ebdf70a2b1efbd8b8d80fd040a926b6` | Tercatat pada audit |
| Gotenberg | `gotenberg/gotenberg:8-chromium` | Digest ada di audit |
| PgBouncer | `edoburu/pgbouncer:v1.25.2-p0` | Digest ada di audit |
| Redis | `redis:7-alpine` | Digest ada di audit |

## Environment URL

| Environment | Frontend | Backend |
|---|---|---|
| Staging | `https://dev-frontend-app.ultra-fit.id/` | `https://dev-backend-app.ultra-fit.id/` |
| Production | `https://app.ultra-fit.id/` | `https://be-superapp.ultra-fit.id/` |

## Yang belum ada pada audit ini

- Commit/tag frontend yang sedang aktif.
- Commit/tag dan artifact mobile yang diterima.
- Digest/image staging.
- Bukti Ingress final setelah rollout selesai.
- Bukti backup-restore, monitoring, dan on-call.

Item tersebut harus diambil dari CI/CD atau environment terkait; jangan diisi
dengan asumsi dari image backend production.
