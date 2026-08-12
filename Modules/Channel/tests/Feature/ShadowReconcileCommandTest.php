<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ShadowReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_ID = '778899';

    private const DAY = '2026-08-10';

    private Channel $shopee;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = $this->makeShop(self::SHOP_ID, 'Shopee Utama');
    }

    private function makeShop(string $shopId, string $name): ChannelShop
    {
        return ChannelShop::create([
            'channel_id'        => $this->shopee->id,
            'shop_id'           => $shopId,
            'shop_name'         => $name,
            'access_token'      => 'valid-token',
            'refresh_token'     => 'refresh-token',
            'token_expires_at'  => now()->addHours(4),
            'is_active'         => true,
            'is_shadow_mode'    => true,
            'shadow_started_at' => Carbon::parse('2026-08-01 00:00:00', 'Asia/Jakarta'),
        ]);
    }

    private function makeOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'salesorder_no'    => 'SHW-' . uniqid(),
            'channel_order_no' => 'CO-' . uniqid(),
            'channel_shop_id'  => self::SHOP_ID,
            'customer_name'    => 'Buyer',
            'source'           => 'shopee',
            'channel_status'   => 'COMPLETED',
            'status'           => 'shipped',
            'transaction_date' => Carbon::parse(self::DAY . ' 12:00:00', 'Asia/Jakarta')->utc(),
            'sub_total'        => 100000,
            'total_disc'       => 0,
            'total_tax'        => 0,
            'shipping_cost'    => 0,
            'insurance_cost'   => 0,
            'grand_total'      => 100000,
            'is_paid'          => true,
            'is_settled'       => false,
            'is_shadow'        => true,
        ], $overrides));
    }

    private function csv(array $rows, array $columns = ['channel_order_no', 'grand_total', 'channel_status']): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jubelio') . '.csv';
        $handle = fopen($path, 'wb');
        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    private function resultFor(string $shopId = self::SHOP_ID): array
    {
        $file = collect(Storage::disk('local')->allFiles('shadow-reconcile'))->first();
        $payload = json_decode(Storage::disk('local')->get($file), true);

        return collect($payload['shops'])->firstWhere('shop_id', $shopId);
    }

    public function test_counts_only_shadow_orders_of_that_shop(): void
    {
        $this->makeOrder(['channel_order_no' => 'A-1']);
        $this->makeOrder(['channel_order_no' => 'A-2']);
        $this->makeOrder(['channel_order_no' => 'B-1', 'is_shadow' => false]);
        $this->makeOrder(['channel_order_no' => 'C-1', 'channel_shop_id' => '111222']);

        $this->artisan('channel:shadow-reconcile', ['--date' => self::DAY])->assertSuccessful();

        $result = $this->resultFor();

        $this->assertSame(2, $result['orders_ours'], 'Order non-shadow dan order toko lain tidak boleh ikut terhitung.');
        $this->assertEquals(200000, $result['value_ours']);
    }

    public function test_orders_before_cutoff_are_excluded(): void
    {
        $this->makeOrder([
            'channel_order_no' => 'OLD-1',
            'transaction_date' => Carbon::parse('2026-07-20 12:00:00', 'Asia/Jakarta')->utc(),
        ]);
        $this->makeOrder(['channel_order_no' => 'NEW-1']);

        $this->artisan('channel:shadow-reconcile', [
            '--from' => '2026-07-15',
            '--to'   => self::DAY,
        ])->assertSuccessful();

        $this->assertSame(
            1,
            $this->resultFor()['orders_ours'],
            'Cutoff shadow_started_at adalah lantai keras — order sebelum itu di luar scope.',
        );
    }

    public function test_detects_orders_missing_on_each_side(): void
    {
        $this->makeOrder(['channel_order_no' => 'SAMA-1']);
        $this->makeOrder(['channel_order_no' => 'HANYA-KITA']);

        $path = $this->csv([
            ['SAMA-1', 100000, 'COMPLETED'],
            ['HANYA-JUBELIO', 100000, 'COMPLETED'],
        ]);

        $this->artisan('channel:shadow-reconcile', ['--date' => self::DAY, '--jubelio' => $path])
            ->assertSuccessful();

        $result = $this->resultFor();

        $this->assertSame(['HANYA-JUBELIO'], $result['missing_in_ours']);
        $this->assertSame(['HANYA-KITA'], $result['missing_in_jubelio']);
        $this->assertSame(1, $result['matched']);
    }

    public function test_value_difference_within_tolerance_is_not_a_mismatch(): void
    {
        $this->makeOrder(['channel_order_no' => 'T-1', 'grand_total' => 100050]);

        $path = $this->csv([['T-1', 100000, 'COMPLETED']]);

        $this->artisan('channel:shadow-reconcile', [
            '--date'      => self::DAY,
            '--jubelio'   => $path,
            '--tolerance' => 100,
        ])->assertSuccessful();

        $this->assertSame([], $this->resultFor()['value_mismatch']);
    }

    public function test_value_difference_beyond_tolerance_is_reported(): void
    {
        $this->makeOrder(['channel_order_no' => 'T-2', 'grand_total' => 150000]);

        $path = $this->csv([['T-2', 100000, 'COMPLETED']]);

        $this->artisan('channel:shadow-reconcile', [
            '--date'      => self::DAY,
            '--jubelio'   => $path,
            '--tolerance' => 100,
        ])->assertSuccessful();

        $this->assertCount(1, $this->resultFor()['value_mismatch']);
    }

    public function test_status_comparison_ignores_letter_case(): void
    {
        $this->makeOrder(['channel_order_no' => 'S-1', 'channel_status' => 'COMPLETED']);

        $path = $this->csv([['S-1', 100000, 'completed']]);

        $this->artisan('channel:shadow-reconcile', ['--date' => self::DAY, '--jubelio' => $path])
            ->assertSuccessful();

        $this->assertSame([], $this->resultFor()['status_mismatch']);
    }

    public function test_order_wrong_on_both_value_and_status_is_penalised_once(): void
    {
        $this->makeOrder(['channel_order_no' => 'X-1', 'grand_total' => 999999, 'channel_status' => 'CANCELLED']);
        $this->makeOrder(['channel_order_no' => 'OK-1']);
        $this->makeOrder(['channel_order_no' => 'OK-2']);
        $this->makeOrder(['channel_order_no' => 'OK-3']);

        $path = $this->csv([
            ['X-1', 100000, 'COMPLETED'],
            ['OK-1', 100000, 'COMPLETED'],
            ['OK-2', 100000, 'COMPLETED'],
            ['OK-3', 100000, 'COMPLETED'],
        ]);

        $this->artisan('channel:shadow-reconcile', ['--date' => self::DAY, '--jubelio' => $path])
            ->assertSuccessful();

        $result = $this->resultFor();

        $this->assertCount(1, $result['value_mismatch']);
        $this->assertCount(1, $result['status_mismatch']);
        $this->assertEquals(
            75,
            $result['match_rate'],
            'Satu order bermasalah dari empat = 75%. Order yang salah nilai DAN statusnya tetap satu order, bukan dua.',
        );
    }

    public function test_csv_rows_are_split_per_shop_when_shop_id_column_present(): void
    {
        $other = $this->makeShop('111222', 'Shopee Kedua');

        $this->makeOrder(['channel_order_no' => 'A-1']);
        $this->makeOrder(['channel_order_no' => 'B-1', 'channel_shop_id' => $other->shop_id]);

        $path = $this->csv([
            ['A-1', 100000, 'COMPLETED', self::SHOP_ID],
            ['B-1', 100000, 'COMPLETED', '111222'],
        ], ['channel_order_no', 'grand_total', 'channel_status', 'shop_id']);

        $this->artisan('channel:shadow-reconcile', ['--date' => self::DAY, '--jubelio' => $path])
            ->assertSuccessful();

        foreach ([self::SHOP_ID, '111222'] as $shopId) {
            $result = $this->resultFor($shopId);

            $this->assertSame([], $result['missing_in_ours'], "Toko {$shopId} tidak boleh mewarisi baris toko lain.");
            $this->assertEquals(100, $result['match_rate']);
        }
    }

    public function test_csv_without_order_number_column_fails(): void
    {
        $this->makeOrder();

        $path = $this->csv([['100000', 'COMPLETED']], ['grand_total', 'channel_status']);

        $this->artisan('channel:shadow-reconcile', ['--date' => self::DAY, '--jubelio' => $path])
            ->assertFailed();
    }

    public function test_unreadable_csv_path_fails(): void
    {
        $this->artisan('channel:shadow-reconcile', [
            '--date'    => self::DAY,
            '--jubelio' => '/path/yang/tidak/ada.csv',
        ])->assertFailed();
    }
}
