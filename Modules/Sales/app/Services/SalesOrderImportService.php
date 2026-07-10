<?php

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\InternalStore;
use Modules\Sales\Models\SalesOrder;
use Modules\Supplier\Models\Salesman;
use Modules\Warehouse\Models\Location;

class SalesOrderImportService
{
    public function __construct(
        private SalesOrderManualService $manualService,
    ) {}

    public function processSheet(Collection $rows): array
    {
        $groups = $this->groupRows($rows);

        $total = 0;
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($groups as $group) {
            $total++;
            $headerRow = $group['header'];
            $itemRows = $group['items'];
            $rowNumbers = $group['row_numbers'];

            try {
                $payload = $this->buildPayload($headerRow, $itemRows);
                $this->manualService->create($payload);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                foreach ($rowNumbers as $rowNum) {
                    $errors[] = [
                        'row_number' => $rowNum,
                        'attribute' => null,
                        'message' => $e->getMessage(),
                        'row_snapshot' => $headerRow,
                    ];
                }
            }
        }

        return compact('total', 'success', 'failed', 'errors');
    }

    private function groupRows(Collection $rows): array
    {
        $groups = [];
        $currentKey = null;
        $keyIndex = [];

        foreach ($rows as $index => $row) {
            $data = $row instanceof Collection ? $row->toArray() : (array) $row;
            $rowNumber = $index + 2; 

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $soNo = trim((string) ($data['no_pesanan'] ?? ''));

            if ($soNo !== '') {
                if (isset($keyIndex[$soNo])) {
                    $gi = $keyIndex[$soNo];
                    $groups[$gi]['items'][] = $data;
                    $groups[$gi]['row_numbers'][] = $rowNumber;
                } else {
                    $currentKey = $soNo;
                    $gi = count($groups);
                    $keyIndex[$soNo] = $gi;
                    $groups[] = [
                        'header' => $data,
                        'items' => [$data],
                        'row_numbers' => [$rowNumber],
                    ];
                }
            } elseif ($currentKey !== null) {
                $gi = $keyIndex[$currentKey];
                $groups[$gi]['items'][] = $data;
                $groups[$gi]['row_numbers'][] = $rowNumber;
            }
        }

        return $groups;
    }

    private function isEmptyRow(array $data): bool
    {
        $check = [
            $data['no_pesanan'] ?? null,
            $data['nama_pelanggan'] ?? null,
            $data['sku'] ?? null,
        ];

        return collect($check)->filter(fn ($v) => $v !== null && trim((string) $v) !== '')->isEmpty();
    }

    private function buildPayload(array $header, array $itemRows): array
    {
        $store = $this->resolveStore(trim((string) ($header['toko'] ?? '')));
        $location = $this->resolveLocation(trim((string) ($header['lokasi'] ?? '')));

        $validationErrors = [];
        if (! $store) {
            $validationErrors[] = 'Toko "' . ($header['toko'] ?? '') . '" tidak ditemukan';
        }
        if (! $location) {
            $validationErrors[] = 'Lokasi "' . ($header['lokasi'] ?? '') . '" tidak ditemukan';
        }
        if (empty(trim((string) ($header['nama_pelanggan'] ?? '')))) {
            $validationErrors[] = 'Nama Pelanggan wajib diisi';
        }
        if (empty(trim((string) ($header['tanggal'] ?? '')))) {
            $validationErrors[] = 'Tanggal wajib diisi';
        }

        $items = $this->buildItems($itemRows, $validationErrors);

        if (empty($items) && empty($validationErrors)) {
            $validationErrors[] = 'Tidak ada item valid dalam pesanan';
        }
        if (! empty($validationErrors)) {
            throw new \RuntimeException(implode('; ', $validationErrors));
        }

        $courier = trim((string) ($header['kurir'] ?? ''));
        $isSelfPickup = $courier === '' || mb_stripos($courier, 'Kirim Sendiri') !== false;
        $deliveryMethod = $isSelfPickup
            ? SalesOrder::DELIVERY_SELF_PICKUP
            : SalesOrder::DELIVERY_COURIER;

        $salesman = $this->resolveSalesman(trim((string) ($header['salesman'] ?? '')));

        return [
            'salesorder_no' => trim((string) ($header['no_pesanan'] ?? '')) ?: '[auto]',
            'no_ref' => $header['no_ref'] ?? null,
            'transaction_date' => $this->parseDate($header['tanggal']),
            'internal_store_id' => $store->id,
            'location_id' => $location->id,
            'salesman_id' => $salesman?->id,
            'customer_name' => trim((string) $header['nama_pelanggan']),
            'note' => $header['keterangan'] ?? null,

            'is_paid' => $this->parseBool($header['sudah_lunas'] ?? false),
            'is_cod' => $this->parseBool($header['cod'] ?? false),
            'price_includes_tax' => $this->parseBool($header['harga_termasuk_pajak'] ?? false),

            'delivery_method' => $deliveryMethod,
            'shipping_provider' => $isSelfPickup ? null : $courier,
            'tracking_number' => $header['no_resi'] ?? null,
            'order_weight_gram' => $this->parseNumeric($header['berat_gram'] ?? null),

            'shipping_full_name' => trim((string) ($header['nama_penerima'] ?? '')) ?: trim((string) $header['nama_pelanggan']),
            'shipping_phone' => $header['no_telp_penerima'] ?? null,
            'shipping_address' => $header['alamat_lengkap'] ?? null,
            'shipping_area' => $header['kecamatan'] ?? null,
            'shipping_city' => $header['kota'] ?? null,
            'shipping_province' => $header['provinsi'] ?? null,
            'shipping_post_code' => $header['kode_pos'] ?? null,

            'shipping_cost' => $this->parseNumeric($header['ongkos_kirim'] ?? 0),
            'shipping_discount' => $this->parseNumeric($header['diskon_ongkir'] ?? 0),
            'insurance_cost' => $this->parseNumeric($header['asuransi'] ?? 0),
            'other_discount' => $this->parseNumeric($header['diskon_lainnya'] ?? 0),
            'order_processing_fee' => $this->parseNumeric($header['biaya_proses'] ?? 0),

            'items' => $items,
        ];
    }

    private function buildItems(array $itemRows, array &$errors): array
    {
        $items = [];

        foreach ($itemRows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $variant = ProductVariant::where('sku', $sku)->where('is_active', true)->first();
            if (! $variant) {
                $errors[] = "SKU \"{$sku}\" tidak ditemukan atau tidak aktif";
                continue;
            }

            $qty = (int) ($row['qty'] ?? 0);
            if ($qty < 1) {
                $errors[] = "Qty untuk SKU \"{$sku}\" harus minimal 1";
                continue;
            }

            $price = $row['harga'] ?? null;
            $price = ($price !== null && trim((string) $price) !== '')
                ? (float) $price
                : (float) ($variant->sell_price ?? 0);

            $items[] = [
                'item_id' => $variant->id,
                'sku' => $variant->sku,
                'description' => $variant->product?->name ?? $variant->sku,
                'qty_in_base' => $qty,
                'price' => $price,
                'disc' => $this->parseNumeric($row['nilai_diskon'] ?? 0),
                'tax_amount' => $this->parseNumeric($row['pajak_nominal'] ?? 0),
            ];
        }

        return $items;
    }

    private function resolveStore(?string $name): ?InternalStore
    {
        if (! $name) {
            return null;
        }
        $clean = preg_replace('/\s*\(.*\)\s*$/', '', $name);

        return InternalStore::where('is_active', true)
            ->where(fn ($q) => $q->where('name', $clean)->orWhere('code', $clean)->orWhere('name', $name))
            ->first();
    }

    private function resolveLocation(?string $name): ?Location
    {
        if (! $name) {
            return null;
        }
        $clean = preg_replace('/\s*\(.*\)\s*$/', '', $name);

        return Location::where('is_active', true)
            ->where(fn ($q) => $q->where('location_name', $clean)->orWhere('location_code', $clean)->orWhere('location_name', $name))
            ->first();
    }

    private function resolveSalesman(?string $name): ?Salesman
    {
        if (! $name) {
            return null;
        }

        return Salesman::where('name', $name)->first();
    }

    private function parseDate($value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return now()->format('Y-m-d');
        }

        $v = trim((string) $value);

        if (is_numeric($v) && (int) $v > 30000) {
            return Carbon::createFromFormat('Y-m-d', '1899-12-30')->addDays((int) $v)->format('Y-m-d');
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $v)) {
            return Carbon::createFromFormat('d-m-Y', $v)->format('Y-m-d');
        }

        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $v)) {
            return Carbon::createFromFormat('d/m/Y', $v)->format('Y-m-d');
        }

        return $v;
    }

    private function parseBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = mb_strtolower(trim((string) $value));

        return in_array($v, ['true', '1', 'ya', 'yes'], true);
    }

    private function parseNumeric($value): float
    {
        if ($value === null) {
            return 0;
        }

        return (float) $value;
    }
}
