// Uji lost update pada putaway: N pemindahan stok bersamaan antar-bin.
//
// Berbeda dengan picking-race-multi.js yang menguji batas stok, skrip ini
// menguji KEUTUHAN mutasi. Stok minus memang diizinkan di sistem ini
// (config inventory.allow_negative_stock default true), jadi "stok jadi minus"
// bukan kegagalan. Yang tidak boleh terjadi adalah dua penulis membaca nilai
// yang sama lalu saling menimpa, sehingga sebagian mutasi hilang.
//
// Stok bin sumber sengaja dibuat berlimpah supaya tidak ada request yang gagal
// karena kehabisan. Dengan begitu setiap selisih angka murni menandakan
// lost update.
//
// Tiga hal yang diperiksa setelah semua request selesai:
//   1. Bin sumber turun PERSIS sebanyak request yang berhasil.
//   2. Bin tujuan naik PERSIS sebanyak yang sama.
//   3. Total stok (sumber + tujuan) tidak berubah sama sekali.
//
// Prasyarat di server target:
//   docker exec cilupbah-staging env RACE_PUTAWAY_STOCK=1000 \
//     php artisan db:seed \
//     --class="Modules\Outbound\Database\Seeders\RaceConditionSeeder" --force
//
// Jalankan:
//   docker run --rm -i \
//     -e BASE_URL=https://staging.ultra-fit.id \
//     -e EMAIL=cilupbah@ultra-fit.id \
//     -e PASSWORD=password \
//     -e VUS=100 \
//     grafana/k6 run - < tests/Load/putaway-race.js

import http from "k6/http";
import { Counter } from "k6/metrics";

const BASE_URL = __ENV.BASE_URL;
const SKU = __ENV.SKU || "RACE-SKU-02";
const VUS = Number(__ENV.VUS || 100);
const QTY = Number(__ENV.QTY || 1);

const moved = new Counter("putaway_ok");
const refused = new Counter("putaway_refused");
const errored = new Counter("putaway_errored");

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
    putaway_errored: ["count==0"],
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

function findVariantId(token) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory?search=${encodeURIComponent(SKU)}&per_page=20`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal cari SKU ${SKU} (HTTP ${res.status}): ${res.body}`);
  }

  const rows = res.json("data") || [];
  const row = rows.find((r) => r.item_code === SKU);

  if (!row) {
    throw new Error(`SKU ${SKU} tidak ditemukan. Jalankan RaceConditionSeeder dulu.`);
  }

  return row.item_id;
}

// Daftar bin milik satu lokasi. Dipakai untuk menemukan bin tujuan, karena
// bin yang stoknya masih 0 belum tentu muncul di daftar stok.
function binsOfLocation(token, locationId) {
  const res = http.get(
    `${BASE_URL}/api/v1/locations/${locationId}/bins?per_page=200`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca daftar bin (HTTP ${res.status}): ${res.body}`);
  }

  return res.json("data") || [];
}

// Stok satu SKU pada satu bin. Baris yang belum pernah ada dianggap 0.
function onHandAt(token, variantId, binId) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory/stocks?filter[item_id]=${variantId}&filter[bin_id]=${binId}&per_page=10`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca stok bin (HTTP ${res.status}): ${res.body}`);
  }

  return (res.json("data") || []).reduce((sum, r) => sum + Number(r.on_hand), 0);
}

// Mengembalikan baris inventory per-bin: [{ binId, locationId, onHand }]
function binRows(token, variantId) {
  const res = http.get(
    `${BASE_URL}/api/v1/inventory/stocks?filter[item_id]=${variantId}&per_page=100`,
    authHeaders(token),
  );

  if (res.status !== 200) {
    throw new Error(`Gagal baca stok per-bin (HTTP ${res.status}): ${res.body}`);
  }

  return (res.json("data") || [])
    .filter((r) => r.bin_id)
    .map((r) => ({
      binId: r.bin_id,
      locationId: r.location_id,
      onHand: Number(r.on_hand),
    }));
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
  const variantId = findVariantId(token);
  const rows = binRows(token, variantId);

  if (rows.length === 0) {
    throw new Error(`SKU ${SKU} tidak punya baris stok sama sekali. Jalankan seeder dulu.`);
  }

  // Bin dengan stok terbanyak jadi sumber.
  const source = [...rows].sort((a, b) => b.onHand - a.onHand)[0];

  // Bin tujuan TIDAK dicari lewat daftar stok, karena bin yang masih 0 belum
  // tentu punya baris di sana. Ambil dari daftar bin milik lokasi ini.
  const bins = binsOfLocation(token, source.locationId);
  const preferred = __ENV.DEST_BIN_CODE;

  const destinationBin =
    (preferred && bins.find((b) => b.bin_final_code === preferred)) ||
    bins.find((b) => b.id !== source.binId && !b.is_inbound) ||
    bins.find((b) => b.id !== source.binId);

  if (!destinationBin) {
    throw new Error(
      `Tidak ada bin lain di lokasi ini selain bin sumber. ` +
        "Putaway butuh dua bin berbeda — jalankan seeder dulu.",
    );
  }

  const destinationBefore = onHandAt(token, variantId, destinationBin.id);

  const destination = {
    binId: destinationBin.id,
    onHand: destinationBefore,
  };

  const needed = VUS * QTY;

  console.log(`SKU ${SKU} (${variantId}).`);
  console.log(`Sumber bin ${source.binId}: ${source.onHand}`);
  console.log(`Tujuan bin ${destinationBin.bin_final_code} (${destination.binId}): ${destination.onHand}`);
  console.log(`${VUS} request x qty ${QTY} = ${needed} unit akan dipindahkan.`);

  if (source.onHand < needed) {
    console.warn(
      `Stok sumber (${source.onHand}) lebih kecil dari total pemindahan (${needed}). ` +
        "Kalau allow_negative_stock dimatikan, sebagian request akan ditolak dan " +
        "hasilnya jadi sulit dibaca. Seed ulang dengan RACE_PUTAWAY_STOCK lebih besar.",
    );
  }

  return {
    token,
    variantId,
    locationId: source.locationId,
    sourceBinId: source.binId,
    destinationBinId: destination.binId,
    sourceBefore: source.onHand,
    destinationBefore: destination.onHand,
  };
}

export default function (data) {
  const res = http.post(
    `${BASE_URL}/api/v1/inventory/putaway`,
    JSON.stringify({
      item_id: data.variantId,
      location_id: data.locationId,
      source_bin_id: data.sourceBinId,
      destination_bin_id: data.destinationBinId,
      qty: QTY,
      created_by: "k6-race",
    }),
    authHeaders(data.token),
  );

  if (res.status >= 200 && res.status < 300) {
    moved.add(1);
  } else if (res.status === 409 || res.status === 422 || res.status === 400) {
    refused.add(1);
  } else {
    errored.add(1);
    console.error(`Status tak terduga ${res.status}: ${res.body}`);
  }
}

export function teardown(data) {
  const sourceAfter = onHandAt(data.token, data.variantId, data.sourceBinId);
  const destinationAfter = onHandAt(data.token, data.variantId, data.destinationBinId);

  const takenFromSource = data.sourceBefore - sourceAfter;
  const addedToDestination = destinationAfter - data.destinationBefore;

  const totalBefore = data.sourceBefore + data.destinationBefore;
  const totalAfter = sourceAfter + destinationAfter;

  console.log(`Sumber : ${data.sourceBefore} → ${sourceAfter} (turun ${takenFromSource})`);
  console.log(`Tujuan : ${data.destinationBefore} → ${destinationAfter} (naik ${addedToDestination})`);
  console.log(`Total  : ${totalBefore} → ${totalAfter}`);

  // 1. Stok tidak boleh tercipta atau lenyap. Ini invarian paling keras:
  //    putaway cuma memindahkan, tidak menambah atau mengurangi.
  if (totalAfter !== totalBefore) {
    console.error(
      `GAGAL — total stok berubah dari ${totalBefore} jadi ${totalAfter}. ` +
        "Ada mutasi yang setengah jadi: satu sisi tercatat, sisi lain tidak.",
    );

    return;
  }

  // 2. Kedua sisi harus bergerak sama banyak.
  if (takenFromSource !== addedToDestination) {
    console.error(
      `GAGAL — sumber turun ${takenFromSource} tapi tujuan cuma naik ${addedToDestination}. ` +
        "Selisihnya adalah update yang hilang karena saling menimpa.",
    );

    return;
  }

  console.log(
    `LULUS — ${takenFromSource} unit berpindah utuh, total stok tetap ${totalAfter}. ` +
      "Tidak ada update yang hilang meski request datang bersamaan.",
  );

  console.log(
    "Bandingkan angka di atas dengan putaway_ok pada ringkasan: " +
      "keduanya harus sama (dikali qty per request).",
  );
}
