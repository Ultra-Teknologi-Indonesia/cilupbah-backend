// Uji lost update pada split item: N pemecahan stok bersamaan.
//
// Split memecah satuan besar jadi satuan kecil (mis. 1 dus jadi 12 pcs), jadi
// SKU sumber dan SKU tujuan bergerak dengan jumlah yang BERBEDA. Karena itu
// invariannya bukan kekekalan total seperti pada putaway, melainkan keutuhan
// masing-masing sisi:
//
//   sumber turun = jumlah request berhasil x QTY_TO_SPLIT
//   tujuan naik  = jumlah request berhasil x SPLIT_INTO_QTY
//
// Selisih sekecil apa pun berarti ada mutasi yang hilang karena dua penulis
// membaca nilai yang sama lalu saling menimpa.
//
// Stok sumber dibuat berlimpah supaya tidak ada request yang gagal karena
// kehabisan — dengan begitu setiap selisih murni menandakan lost update.
//
// Prasyarat di server target:
//   docker exec cilupbah-staging env RACE_SPLIT_STOCK=1000 \
//     php artisan db:seed \
//     --class="Modules\Outbound\Database\Seeders\RaceConditionSeeder" --force
//
// Jalankan:
//   docker run --rm -i \
//     -e BASE_URL=https://staging.ultra-fit.id \
//     -e EMAIL=cilupbah@ultra-fit.id \
//     -e PASSWORD=password \
//     -e VUS=100 \
//     grafana/k6 run - < tests/Load/split-race.js

import http from "k6/http";
import { Counter } from "k6/metrics";

const BASE_URL = __ENV.BASE_URL;
const SOURCE_SKU = __ENV.SOURCE_SKU || "RACE-SKU-03";
const TARGET_SKU = __ENV.TARGET_SKU || "RACE-SKU-04";
const VUS = Number(__ENV.VUS || 100);
const QTY_TO_SPLIT = Number(__ENV.QTY_TO_SPLIT || 1);
const SPLIT_INTO_QTY = Number(__ENV.SPLIT_INTO_QTY || 12);

const split = new Counter("split_ok");
const refused = new Counter("split_refused");
const errored = new Counter("split_errored");

export const options = {
  scenarios: {
    race: {
      executor: "per-vu-iterations",
      vus: VUS,
      iterations: 1,
      maxDuration: "3m",
    },
  },
  thresholds: {
    split_errored: ["count==0"],
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

function findVariantId(token, sku) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory?search=${encodeURIComponent(sku)}&per_page=20`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal cari SKU ${sku} (HTTP ${res.status}): ${res.body}`);
  }

  const row = (res.json("data") || []).find((r) => r.item_code === sku);

  if (!row) {
    throw new Error(`SKU ${sku} tidak ditemukan. Jalankan RaceConditionSeeder dulu.`);
  }

  return row.item_id;
}

function stockRow(token, variantId) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory/stocks?filter[item_id]=${variantId}&per_page=100`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca stok (HTTP ${res.status}): ${res.body}`);
  }

  const rows = (res.json("data") || []).filter((r) => r.bin_id);

  if (rows.length === 0) {
    throw new Error(`Tidak ada baris stok untuk ${variantId}. Seed ulang.`);
  }

  // Fixture menaruh kedua SKU di satu bin, jadi ambil yang stoknya terbanyak.
  const row = [...rows].sort((a, b) => Number(b.on_hand) - Number(a.on_hand))[0];

  return {
    binId: row.bin_id,
    locationId: row.location_id,
    onHand: Number(row.on_hand),
  };
}

function onHandOf(token, variantId, binId) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory/stocks?filter[item_id]=${variantId}&filter[bin_id]=${binId}&per_page=10`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca stok akhir (HTTP ${res.status}): ${res.body}`);
  }

  return (res.json("data") || []).reduce((sum, r) => sum + Number(r.on_hand), 0);
}

export function setup() {
  if (!BASE_URL) throw new Error("Env BASE_URL wajib diisi");

  // Login sekali saja: endpoint login dibatasi throttle:10,1.
  const res = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }),
    { headers: { "Content-Type": "application/json" } },
  );

  if (res.status !== 200) {
    throw new Error(`Login gagal (HTTP ${res.status}): ${res.body}`);
  }

  const token = res.json("data.access_token");

  const sourceId = findVariantId(token, SOURCE_SKU);
  const targetId = findVariantId(token, TARGET_SKU);

  const source = stockRow(token, sourceId);
  const targetBefore = onHandOf(token, targetId, source.binId);

  const needed = VUS * QTY_TO_SPLIT;

  console.log(`Sumber ${SOURCE_SKU}: ${source.onHand} di bin ${source.binId}`);
  console.log(`Tujuan ${TARGET_SKU}: ${targetBefore} di bin yang sama`);
  console.log(
    `${VUS} request: tiap request pecah ${QTY_TO_SPLIT} jadi ${SPLIT_INTO_QTY}. ` +
      `Total ${needed} keluar, ${VUS * SPLIT_INTO_QTY} masuk.`,
  );

  if (source.onHand < needed) {
    console.warn(
      `Stok sumber (${source.onHand}) lebih kecil dari total yang dipecah (${needed}). ` +
        "Kalau allow_negative_stock dimatikan, sebagian request akan ditolak. " +
        "Seed ulang dengan RACE_SPLIT_STOCK lebih besar.",
    );
  }

  return {
    token,
    sourceId,
    targetId,
    binId: source.binId,
    locationId: source.locationId,
    sourceBefore: source.onHand,
    targetBefore,
  };
}

export default function (data) {
  const res = http.post(
    `${BASE_URL}/api/v1/inventory/items/split-item`,
    JSON.stringify({
      source_item_id: data.sourceId,
      target_item_id: data.targetId,
      location_id: data.locationId,
      bin_id: data.binId,
      qty_to_split: QTY_TO_SPLIT,
      split_into_qty: SPLIT_INTO_QTY,
    }),
    authHeaders(data.token),
  );

  if (res.status >= 200 && res.status < 300) {
    split.add(1);
  } else if (res.status === 409 || res.status === 422 || res.status === 400) {
    refused.add(1);
  } else {
    errored.add(1);
    console.error(`Status tak terduga ${res.status}: ${res.body}`);
  }
}

export function teardown(data) {
  const sourceAfter = onHandOf(data.token, data.sourceId, data.binId);
  const targetAfter = onHandOf(data.token, data.targetId, data.binId);

  const taken = data.sourceBefore - sourceAfter;
  const produced = targetAfter - data.targetBefore;

  console.log(`Sumber : ${data.sourceBefore} → ${sourceAfter} (turun ${taken})`);
  console.log(`Tujuan : ${data.targetBefore} → ${targetAfter} (naik ${produced})`);

  if (taken % QTY_TO_SPLIT !== 0) {
    console.error(
      `GAGAL — sumber turun ${taken}, bukan kelipatan ${QTY_TO_SPLIT}. ` +
        "Ada mutasi yang tercatat sebagian.",
    );

    return;
  }

  const completed = taken / QTY_TO_SPLIT;
  const expectedProduced = completed * SPLIT_INTO_QTY;

  // Kedua sisi harus mencerminkan jumlah split yang sama. Kalau sumber turun
  // untuk 80 split tapi tujuan cuma naik senilai 74, enam split kehilangan
  // sisi masuknya — stok menguap tanpa jejak.
  if (produced !== expectedProduced) {
    console.error(
      `GAGAL — sumber turun setara ${completed} split (harusnya tujuan naik ${expectedProduced}), ` +
        `tapi tujuan cuma naik ${produced}. Selisih ${expectedProduced - produced} adalah update yang hilang.`,
    );

    return;
  }

  console.log(
    `LULUS — ${completed} split tercatat utuh di kedua sisi ` +
      `(${taken} keluar, ${produced} masuk). Tidak ada update yang hilang.`,
  );

  console.log("Bandingkan `completed` di atas dengan split_ok pada ringkasan: harus sama.");
}
