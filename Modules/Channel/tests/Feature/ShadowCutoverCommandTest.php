<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ShadowCutoverCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_ID = '778899';

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->shop = ChannelShop::create([
            'channel_id'        => $channel->id,
            'shop_id'           => self::SHOP_ID,
            'shop_name'         => 'Shopee Utama',
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
            'channel_status'   => 'READY_TO_SHIP',
            'status'           => 'pending',
            'transaction_date' => Carbon::parse('2026-08-10 12:00:00', 'Asia/Jakarta')->utc(),
            'sub_total'        => 100000,
            'total_disc'       => 0,
            'total_tax'        => 0,
            'shipping_cost'    => 0,
            'insurance_cost'   => 0,
            'grand_total'      => 100000,
            'is_paid'          => true,
            'is_canceled'      => false,
            'is_settled'       => false,
            'is_shadow'        => true,
        ], $overrides));
    }

    public function test_dry_run_leaves_no_trace(): void
    {
        $order = $this->makeOrder();

        $this->artisan('channel:shadow-off', [
            '--shop'    => self::SHOP_ID,
            '--promote' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertTrue(
            (bool) $this->shop->fresh()->is_shadow_mode,
            'Dry-run tidak boleh benar-benar mematikan Shadow Mode.',
        );
        $this->assertTrue(
            (bool) $order->fresh()->is_shadow,
            'Dry-run tidak boleh benar-benar mempromosikan order.',
        );
    }

    public function test_cutover_without_promote_keeps_orders_as_archive(): void
    {
        $order = $this->makeOrder();

        $this->artisan('channel:shadow-off', ['--shop' => self::SHOP_ID, '--force' => true])
            ->assertSuccessful();

        $this->assertFalse((bool) $this->shop->fresh()->is_shadow_mode);
        $this->assertTrue(
            (bool) $order->fresh()->is_shadow,
            'Tanpa --promote, order in-flight diselesaikan di sistem lama dan tetap jadi arsip.',
        );
    }

    public function test_promote_only_touches_open_orders(): void
    {
        $open = $this->makeOrder(['status' => 'pending']);
        $shipped = $this->makeOrder(['status' => 'shipped']);
        $canceled = $this->makeOrder(['status' => 'pending', 'is_canceled' => true]);

        $this->artisan('channel:shadow-off', [
            '--shop'    => self::SHOP_ID,
            '--promote' => true,
            '--force'   => true,
        ])->assertSuccessful();

        $this->assertFalse((bool) $open->fresh()->is_shadow, 'Order terbuka harus dipromosikan.');
        $this->assertTrue(
            (bool) $shipped->fresh()->is_shadow,
            'Order yang fulfillment-nya terjadi di sistem lama tidak boleh masuk angka operasional di sini.',
        );
        $this->assertTrue((bool) $canceled->fresh()->is_shadow, 'Order batal tetap arsip.');
    }

    public function test_purge_without_force_deletes_nothing(): void
    {
        $this->makeOrder();

        $this->artisan('channel:shadow-purge', ['--shop' => self::SHOP_ID])->assertSuccessful();

        $this->assertSame(1, SalesOrder::query()->onlyShadow()->count());
    }

    public function test_purge_with_force_deletes_only_shadow_orders(): void
    {
        $shadow = $this->makeOrder();
        $real = $this->makeOrder(['is_shadow' => false]);

        $this->artisan('channel:shadow-purge', ['--shop' => self::SHOP_ID, '--force' => true])
            ->expectsConfirmation('Hapus permanen 1 order shadow?', 'yes')
            ->assertSuccessful();

        $this->assertNull(SalesOrder::find($shadow->id));
        $this->assertNotNull(SalesOrder::find($real->id), 'Order sungguhan tidak boleh ikut terhapus.');
    }

    public function test_purge_before_uses_jakarta_day_boundary(): void
    {
        $keep = $this->makeOrder([
            'channel_order_no' => 'KEEP-1',
            'transaction_date' => Carbon::parse('2026-09-01 03:00:00', 'Asia/Jakarta')->utc(),
        ]);
        $drop = $this->makeOrder([
            'channel_order_no' => 'DROP-1',
            'transaction_date' => Carbon::parse('2026-08-31 21:00:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->artisan('channel:shadow-purge', [
            '--shop'   => self::SHOP_ID,
            '--before' => '2026-09-01',
            '--force'  => true,
        ])->expectsConfirmation('Hapus permanen 1 order shadow?', 'yes')
          ->assertSuccessful();

        $this->assertNull(SalesOrder::find($drop->id), 'Order 31 Agustus WIB ada di dalam scope --before.');
        $this->assertNotNull(
            SalesOrder::find($keep->id),
            'Order 1 September 03:00 WIB sudah di luar scope dan tidak boleh terhapus.',
        );
    }
}
