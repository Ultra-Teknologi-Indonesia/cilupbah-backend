<?php

namespace Modules\Channel\Tests\Feature;

use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Tests\TestCase;

class ShopeeHandoverMethodTest extends TestCase
{
    private function resolve(array $opts, array $infoNeeded, array $addressList = [], array $branchList = []): string
    {
        $svc = app(ShopeeOrderService::class);

        $method = new \ReflectionMethod($svc, 'resolveHandoverMethod');
        $method->setAccessible(true);

        return $method->invoke($svc, $opts, $infoNeeded, $addressList, $branchList);
    }

    public function test_sameday_hanya_pickup_maka_preferensi_counter_diabaikan(): void
    {
        $method = $this->resolve(
            ['preferred_method' => ChannelShop::HANDOVER_DROPOFF],
            ['pickup' => ['address_id', 'pickup_time_id']],
            [['address_id' => 1]],
        );

        $this->assertSame(
            'pickup',
            $method,
            'Shopee hanya menawarkan pickup — preferensi counter tidak boleh memaksa dropoff.',
        );
    }

    public function test_standard_hanya_counter_maka_tidak_dipaksa_pickup(): void
    {
        $method = $this->resolve(
            ['preferred_method' => ChannelShop::HANDOVER_PICKUP],
            ['dropoff' => []],
        );

        $this->assertSame(
            'dropoff',
            $method,
            'Shopee hanya menawarkan counter — memaksa pickup akan ditolak Shopee.',
        );
    }

    public function test_hemat_dua_opsi_maka_preferensi_toko_yang_menentukan(): void
    {
        $infoNeeded = ['dropoff' => [], 'pickup' => ['address_id', 'pickup_time_id']];
        $addressList = [['address_id' => 9]];

        $this->assertSame(
            'dropoff',
            $this->resolve(['preferred_method' => ChannelShop::HANDOVER_DROPOFF], $infoNeeded, $addressList),
            'Dua opsi tersedia dan toko memilih counter — harus counter.',
        );

        $this->assertSame(
            'pickup',
            $this->resolve(['preferred_method' => ChannelShop::HANDOVER_PICKUP], $infoNeeded, $addressList),
            'Dua opsi tersedia dan toko memilih pickup — harus pickup.',
        );
    }

    public function test_tanpa_preferensi_perilaku_lama_dipertahankan(): void
    {
        $method = $this->resolve(
            [],
            ['dropoff' => [], 'pickup' => ['address_id', 'pickup_time_id']],
            [['address_id' => 9]],
        );

        $this->assertSame('pickup', $method, 'Tanpa preferensi, fallback lama (pickup dulu) harus tetap.');
    }

    public function test_method_eksplisit_melewati_negosiasi(): void
    {
        $method = $this->resolve(
            ['method' => 'pickup', 'preferred_method' => ChannelShop::HANDOVER_DROPOFF],
            ['dropoff' => []],
        );

        $this->assertSame('pickup', $method, 'method eksplisit adalah override keras untuk retryPickup.');
    }
}
