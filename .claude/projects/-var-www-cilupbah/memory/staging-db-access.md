---
name: staging-db-access
description: How to run DB queries / tinker against staging — external psql is blocked, must use the docker container
metadata:
  type: project
---

Staging DB (Postgres `cilupbah` on host `172.17.0.1:5432`) only accepts connections from the docker0 gateway — direct `psql` from the host shell fails with `no pg_hba.conf entry for host ...`.

To query the DB or run Eloquent, exec into the app container instead:

```bash
docker exec cilupbah-staging sh -c 'php artisan tinker --execute="use Illuminate\Support\Facades\DB; echo DB::table(\"sales_orders\")->count();"'
```

Containers: `cilupbah-staging` (web, port 8001→8000), `-horizon` (queue), `-scheduler`, `-redis`. Repo is bind-mounted `/var/www/cilupbah:/var/www/html`, so `storage/logs/*` files are the same on host and container. App URL: https://staging.ultra-fit.id.
