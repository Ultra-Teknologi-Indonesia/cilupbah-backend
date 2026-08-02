<?php

namespace Modules\Outbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;

/**
 * Data uji race condition: N picklist terpisah yang SEMUANYA mengambil SKU
 * yang sama dari bin yang sama, dengan stok sengaja dibuat lebih sedikit
 * daripada total permintaan.
 *
 * Tujuannya memaksa perebutan pada satu baris `inventories`, bukan pada baris
 * `picklist_items` — ini skenario gudang yang nyata (beberapa picker berebut
 * SKU laris) dan menguji lock di InventoryRepository, bukan di PicklistService.
 *
 * Jalankan:
 *   php artisan db:seed --class="Modules\Outbound\Database\Seeders\RaceConditionSeeder" --force
 *
 * Atur jumlah lewat env:
 *   RACE_PICKLISTS=10 RACE_STOCK=5 php artisan db:seed --class=... --force
 *
 * Semua data yang dibuat diberi prefix RACE- supaya gampang dicari dan dibuang.
 */
class RaceConditionSeeder extends Seeder
{
    private const PREFIX = 'RACE-';

    public function run(): void
    {
        $itemCount = $this->intFromEnv('RACE_ITEMS', 100);
        $stock = $this->intFromEnv('RACE_STOCK', 10);

        if ($itemCount <= $stock) {
            $this->command->error(
                "Jumlah item ({$itemCount}) harus LEBIH BESAR dari stok ({$stock}), ".
                'kalau tidak tidak ada yang diperebutkan.'
            );

            return;
        }

        $this->purge();

        $userId = $this->seedPicker();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId);
        [$variantId, $sku] = $this->seedProductVariant();

        $this->seedInventory($variantId, $locationId, $binId, $stock);

        // Satu picklist berisi banyak item. pickItem() hanya mengunci baris
        // picklist_items, bukan picklist-nya, jadi item-item ini tetap jalan
        // paralel dan berebut di baris inventories — persis yang mau diuji.
        $picklistId = $this->seedPicklist($locationId, $userId);

        for ($i = 1; $i <= $itemCount; $i++) {
            $this->seedPicklistItem($picklistId, $locationId, $variantId, $sku, $userId, $i);
        }

        $putawayStock = $this->intFromEnv('RACE_PUTAWAY_STOCK', 1000);
        $this->seedPutawayFixture($locationId, $putawayStock);

        $this->command->info("[picking] 1 picklist berisi {$itemCount} item, stok {$stock}.");
        $this->command->info("          Harapan: tepat {$stock} pick berhasil, sisanya ditolak.");
        $this->command->info("[putaway] SKU ".self::PREFIX."SKU-02, bin sumber {$putawayStock}, bin tujuan 0.");
        $this->command->info('          Harapan: semua request berhasil, total stok tetap utuh.');
        $this->command->info('Cari lewat API: GET /api/v1/outbound/picklists?search='.self::PREFIX);
    }

    /**
     * Baca lewat getenv(), bukan env(). Staging menjalankan `config:cache`
     * saat deploy, dan env() di luar file config mengembalikan null begitu
     * config ter-cache — override dari command line jadi tidak terbaca.
     */
    private function intFromEnv(string $key, int $default): int
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : (int) $value;
    }

    /**
     * Buang sisa data uji sebelumnya supaya tiap run mulai dari nol.
     */
    private function purge(): void
    {
        $picklistIds = DB::table('picklists')
            ->where('picklist_no', 'like', self::PREFIX.'%')
            ->pluck('id');

        if ($picklistIds->isNotEmpty()) {
            $itemIds = DB::table('picklist_items')->whereIn('picklist_id', $picklistIds)->pluck('id');
            DB::table('picklist_item_allocations')->whereIn('picklist_item_id', $itemIds)->delete();
            DB::table('picklist_items')->whereIn('picklist_id', $picklistIds)->delete();
            DB::table('picklists')->whereIn('id', $picklistIds)->delete();
        }

        $orderIds = DB::table('sales_orders')
            ->where('salesorder_no', 'like', self::PREFIX.'%')
            ->pluck('id');

        if ($orderIds->isNotEmpty()) {
            DB::table('sales_order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('sales_orders')->whereIn('id', $orderIds)->delete();
        }

        $variantIds = DB::table('product_variants')
            ->where('sku', 'like', self::PREFIX.'%')
            ->pluck('id');

        if ($variantIds->isNotEmpty()) {
            DB::table('inventories')->whereIn('item_id', $variantIds)->delete();
            DB::table('product_variants')->whereIn('id', $variantIds)->delete();
        }
    }

    private function seedPicker(): string
    {
        $existing = DB::table('users')->where('email', 'race-picker@cilupbah.test')->value('id');

        if ($existing) {
            return $existing;
        }

        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Race Picker',
            'email' => 'race-picker@cilupbah.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedLocation(): string
    {
        $existing = DB::table('locations')->where('location_code', self::PREFIX.'WH')->value('id');

        if ($existing) {
            return $existing;
        }

        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => self::PREFIX.'WH',
            'location_name' => 'Gudang Uji Race',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedBin(string $locationId): string
    {
        $code = 'R1-R1-K1-B1';
        $existing = DB::table('location_bins')
            ->where('location_id', $locationId)
            ->where('bin_final_code', $code)
            ->value('id');

        if ($existing) {
            return $existing;
        }

        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $locationId,
            'bin_final_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Fixture untuk uji putaway: satu SKU dengan stok berlimpah di bin sumber
     * dan bin tujuan kosong. Stok dibuat banyak supaya tidak ada request yang
     * ditolak karena kehabisan — dengan begitu setiap penolakan atau selisih
     * angka murni menandakan lost update, bukan stok habis.
     */
    private function seedPutawayFixture(string $locationId, int $stock): void
    {
        $sourceBin = $this->seedBin($locationId, 'R2-R1-K1-B1');
        $destBin = $this->seedBin($locationId, 'R2-R1-K1-B2');

        [$variantId] = $this->seedProductVariant('SKU-02');

        $this->seedInventory($variantId, $locationId, $sourceBin, $stock);
        $this->seedInventory($variantId, $locationId, $destBin, 0);
    }

    /**
     * @return array{0: string, 1: string} [variantId, sku]
     */
    private function seedProductVariant(string $suffix = 'SKU-01'): array
    {
        $categoryId = DB::table('categories')->value('id');

        if (! $categoryId) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Umum',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Produk Uji Race',
            'category_id' => $categoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sku = self::PREFIX.$suffix;
        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$variantId, $sku];
    }

    private function seedInventory(string $itemId, string $locationId, string $binId, int $onHand): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPicklist(string $locationId, string $userId): string
    {
        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => self::PREFIX.'PICK-001',
            'location_id' => $locationId,
            'picker_id' => $userId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $picklistId;
    }

    private function seedPicklistItem(
        string $picklistId,
        string $locationId,
        string $itemId,
        string $sku,
        string $userId,
        int $index,
    ): void {
        $suffix = str_pad((string) $index, 4, '0', STR_PAD_LEFT);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => self::PREFIX.'SO-'.$suffix,
            'customer_name' => 'Pembeli Uji '.$suffix,
            'location_id' => $locationId,
            'status' => 'reserved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_in_base' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('picklist_items')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_ordered' => 1,
            'qty_picked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
