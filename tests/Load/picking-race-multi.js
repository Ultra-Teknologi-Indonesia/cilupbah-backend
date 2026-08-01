// Uji race condition: N request pick bersamaan berebut satu baris inventory.
//
// Seeder menyiapkan satu picklist berisi N item, semuanya SKU yang sama dari
// bin yang sama, dengan stok sengaja jauh lebih kecil dari jumlah item.
// pickItem() hanya mengunci baris picklist_items, jadi item-item ini benar-benar
// jalan paralel dan berebut di baris `inventories` — itulah yang diuji.
//
// Prasyarat di server target:
//   docker exec cilupbah-staging env RACE_ITEMS=100 RACE_STOCK=10 \
//     php artisan db:seed \
//     --class="Modules\Outbound\Database\Seeders\RaceConditionSeeder" --force
//
// Jalankan:
//   docker run --rm -i \
//     -e BASE_URL=https://staging.ultra-fit.id \
//     -e EMAIL=cilupbah@ultra-fit.id \
//     -e PASSWORD=password \
//     -e VUS=100 \
//     grafana/k6 run - < tests/Load/picking-race-multi.js
//
// Turunkan SENTRY_TRACES_SAMPLE_RATE ke 0 sebelum menjalankan: dengan 1.0,
// setiap request terkirim sebagai transaksi dan kuota Sentry cepat habis.

import http from "k6/http";
import { Counter } from "k6/metrics";

const BASE_URL = __ENV.BASE_URL;
const BIN_CODE = __ENV.BIN_CODE || "R1-R1-K1-B1";
const PREFIX = __ENV.PREFIX || "RACE-";
const VUS = Number(__ENV.VUS || 100);

const accepted = new Counter("pick_accepted");
const rejected = new Counter("pick_rejected");
const errored = new Counter("pick_errored");

export const options = {
  scenarios: {
    race: {
      // Satu iterasi per VU, tanpa ramp-up: semua berangkat berbarengan.
      executor: "per-vu-iterations",
      vus: VUS,
      iterations: 1,
      maxDuration: "2m",
    },
  },
  thresholds: {
    // HTTP 500 berarti ada yang tidak tertangani (mis. deadlock).
    pick_errored: ["count==0"],
  },
};

function authHeaders(token) {
  return {
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
    },
  };
}

function findPicklist(token) {
  const res = http.get(
    `${BASE_URL}/api/v1/outbound/picklists?search=${PREFIX}&per_page=10`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal cari picklist uji (HTTP ${res.status}): ${res.body}`);
  }

  const rows = res.json("data") || [];

  if (rows.length === 0) {
    throw new Error(
      `Tidak ada picklist berawalan ${PREFIX}. Jalankan RaceConditionSeeder dulu di server target.`,
    );
  }

  return rows[0].id;
}

function itemsOf(token, picklistId) {
  const res = http.get(
    `${BASE_URL}/api/v1/outbound/picklists/${picklistId}/items?per_page=500`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca item picklist (HTTP ${res.status}): ${res.body}`);
  }

  return res.json("data") || [];
}

// Stok dibaca langsung dari endpoint inventory: satu request, dan inilah
// assertion yang paling tajam untuk oversell.
function onHand(token, itemId) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory/stocks?filter[item_id]=${itemId}&per_page=100`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca stok (HTTP ${res.status}): ${res.body}`);
  }

  return (res.json("data") || []).reduce((sum, row) => sum + Number(row.on_hand), 0);
}

export function setup() {
  if (!BASE_URL) throw new Error("Env BASE_URL wajib diisi");

  // Login sekali saja: endpoint login dibatasi throttle:10,1. Kalau login
  // dilakukan per-VU, yang gagal nanti rate limit, bukan race condition-nya.
  const res = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }),
    { headers: { "Content-Type": "application/json" } },
  );

  if (res.status !== 200) {
    throw new Error(`Login gagal (HTTP ${res.status}): ${res.body}`);
  }

  const token = res.json("data.access_token");
  const picklistId = findPicklist(token);
  const items = itemsOf(token, picklistId);

  if (items.length === 0) {
    throw new Error("Picklist uji tidak punya item.");
  }

  const variantId = items[0].item_id;
  const stockBefore = onHand(token, variantId);

  console.log(`Picklist uji berisi ${items.length} item, ${VUS} VU akan menembak bersamaan.`);
  console.log(`Stok awal: ${stockBefore}.`);

  if (stockBefore <= 0) {
    throw new Error("Stok awal 0. Seed ulang sebelum menjalankan tes.");
  }

  if (VUS <= stockBefore) {
    throw new Error(
      `VUS (${VUS}) harus lebih besar dari stok (${stockBefore}), ` +
        "kalau tidak tidak ada request yang seharusnya ditolak.",
    );
  }

  if (items.length < VUS) {
    throw new Error(
      `Item tersedia (${items.length}) kurang dari VUS (${VUS}). ` +
        `Seed ulang dengan RACE_ITEMS=${VUS} atau lebih.`,
    );
  }

  return {
    token,
    picklistId,
    variantId,
    stockBefore,
    itemIds: items.slice(0, VUS).map((i) => i.id),
  };
}

export default function (data) {
  // Tiap VU memegang item berbeda, jadi yang diperebutkan murni baris inventory.
  const itemId = data.itemIds[__VU - 1];

  const res = http.post(
    `${BASE_URL}/api/v1/outbound/picklists/${data.picklistId}/items/${itemId}/pick`,
    JSON.stringify({ qty_delta: 1, bin_code: BIN_CODE }),
    authHeaders(data.token),
  );

  if (res.status >= 200 && res.status < 300) {
    accepted.add(1);
  } else if (res.status === 409 || res.status === 422) {
    rejected.add(1);
  } else {
    errored.add(1);
    console.error(`Status tak terduga ${res.status}: ${res.body}`);
  }
}

export function teardown(data) {
  const stockAfter = onHand(data.token, data.variantId);
  const consumed = data.stockBefore - stockAfter;

  console.log(`Stok ${data.stockBefore} → ${stockAfter} (terpakai ${consumed}).`);

  if (stockAfter < 0) {
    console.error(
      `GAGAL — stok MINUS (${stockAfter}). Ini oversell paling parah: ` +
        "pemeriksaan stok terlewati oleh request yang jalan bersamaan.",
    );

    return;
  }

  if (consumed > data.stockBefore) {
    console.error(`GAGAL — OVERSELL. Terpakai ${consumed} dari stok ${data.stockBefore}.`);

    return;
  }

  if (consumed === data.stockBefore) {
    console.log(
      `LULUS — tepat ${consumed} pick berhasil, sisanya ditolak, stok berhenti di 0. ` +
        "Lock pada baris inventory menahan request bersamaan dengan benar.",
    );

    return;
  }

  console.warn(
    `Stok tersisa ${stockAfter} padahal masih ada request yang ditolak. ` +
      "Cek apakah ada yang gagal karena alasan lain (lihat log status di atas).",
  );
}
