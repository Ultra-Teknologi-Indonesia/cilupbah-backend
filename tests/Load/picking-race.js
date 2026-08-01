// Uji race condition pada endpoint pick picklist.
//
// Menembak N request `qty_delta: 1` secara bersamaan ke satu item picklist,
// lalu memverifikasi bahwa jumlah request yang sukses persis sama dengan
// sisa qty yang boleh dipick. Kalau yang sukses lebih banyak, berarti ada
// lost update (lock di InventoryRepository/PicklistService bocor).
//
// Jalankan:
//   docker run --rm -i \
//     -e BASE_URL=https://staging.ultra-fit.id \
//     -e EMAIL=cilupbah@ultra-fit.id \
//     -e PASSWORD=password \
//     -e PICKLIST_ID=... \
//     -e ITEM_ID=... \
//     -e BIN_CODE=... \
//     grafana/k6 run - < tests/Load/picking-race.js
//
// Sebelum menjalankan di staging: turunkan SENTRY_TRACES_SAMPLE_RATE ke 0,
// karena setiap request akan terkirim sebagai transaksi dan cepat menghabiskan
// kuota Sentry.

import http from "k6/http";
import { Counter } from "k6/metrics";

const BASE_URL = __ENV.BASE_URL;
const PICKLIST_ID = __ENV.PICKLIST_ID;
const ITEM_ID = __ENV.ITEM_ID;
const BIN_CODE = __ENV.BIN_CODE;
const ATTEMPTS = Number(__ENV.ATTEMPTS || 20);

const accepted = new Counter("pick_accepted");
const rejected = new Counter("pick_rejected");
const errored = new Counter("pick_errored");

export const options = {
  // Semua VU start berbarengan dan masing-masing menembak sekali — inilah
  // balapannya. Jangan pakai ramp-up: request harus tiba serentak.
  scenarios: {
    race: {
      executor: "shared-iterations",
      vus: ATTEMPTS,
      iterations: ATTEMPTS,
      maxDuration: "1m",
    },
  },
  thresholds: {
    // Server tidak boleh 500 sama sekali. Deadlock yang tidak tertangani
    // akan muncul di sini.
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

function fetchItem(token) {
  const res = http.get(
    `${BASE_URL}/api/v1/outbound/picklists/${PICKLIST_ID}/items?per_page=100`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal membaca item picklist (HTTP ${res.status}): ${res.body}`);
  }

  const rows = res.json("data") || [];
  const row = rows.find((r) => r.id === ITEM_ID);

  if (!row) {
    throw new Error(`Item ${ITEM_ID} tidak ada di picklist ${PICKLIST_ID}`);
  }

  return { qtyOrdered: Number(row.qty_ordered), qtyPicked: Number(row.qty_picked) };
}

export function setup() {
  for (const [name, value] of Object.entries({ BASE_URL, PICKLIST_ID, ITEM_ID, BIN_CODE })) {
    if (!value) throw new Error(`Env ${name} wajib diisi`);
  }

  // Login hanya sekali. Endpoint login dibatasi throttle:10,1 — kalau login
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
  const before = fetchItem(token);
  const room = before.qtyOrdered - before.qtyPicked;

  console.log(
    `Awal: qty_picked=${before.qtyPicked} / qty_ordered=${before.qtyOrdered} ` +
      `→ sisa ${room}, akan ditembak ${ATTEMPTS} request.`,
  );

  if (room <= 0) {
    throw new Error("Item sudah penuh, tidak ada sisa qty untuk diuji.");
  }

  if (ATTEMPTS <= room) {
    throw new Error(
      `ATTEMPTS (${ATTEMPTS}) harus LEBIH BESAR dari sisa qty (${room}), ` +
        "kalau tidak, tidak ada request yang seharusnya ditolak.",
    );
  }

  return { token, before, room };
}

export default function (data) {
  const res = http.post(
    `${BASE_URL}/api/v1/outbound/picklists/${PICKLIST_ID}/items/${ITEM_ID}/pick`,
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
  const after = fetchItem(data.token);
  const delta = after.qtyPicked - data.before.qtyPicked;

  console.log(`Akhir: qty_picked=${after.qtyPicked} (naik ${delta}, seharusnya ${data.room})`);

  if (delta === data.room) {
    console.log("LULUS — qty_picked naik persis sebanyak sisa yang tersedia.");
    return;
  }

  if (delta > data.room) {
    console.error(
      `GAGAL — LOST UPDATE. qty_picked naik ${delta}, melebihi sisa ${data.room}. ` +
        "Beberapa request lolos melewati batas: lock tidak menahan request bersamaan.",
    );
    return;
  }

  console.error(
    `GAGAL — qty_picked cuma naik ${delta} dari ${data.room} yang tersedia. ` +
      "Ada update yang hilang atau request ditolak padahal masih ada sisa.",
  );
}
