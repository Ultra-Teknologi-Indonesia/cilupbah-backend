<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

final class PutawayPdfTemplateTest extends TestCase
{
    public function test_putaway_print_uses_receipt_layout_and_shows_placement_quantities(): void
    {
        $putaway = new Putaway([
            'putaway_no' => 'PUT-000000500',
            'status' => Putaway::STATUS_COMPLETED,
            'created_at' => Carbon::create(2026, 9, 1, 10, 0, 0),
        ]);
        $putaway->setRelation('location', (object) ['location_name' => 'Pusat']);
        $putaway->setRelation('inbound', (object) [
            'transaction_number' => 'INB-KAEBFRSZ',
            'reference_number' => 'PO-000001',
        ]);
        $putaway->setRelation('sources', new Collection);

        $variant = new ProductVariant(['sku' => 'BC-WH-IP-14-PRO']);
        $variant->setRelation('product', (object) [
            'name' => 'CILUPBAH Transparent Bumper Circle Case',
        ]);

        $item = new PutawayItem([
            'qty' => 100,
            'putaway_qty' => 93,
        ]);
        $item->setRelation('product', $variant);
        $item->setRelation('destinationBin', null);
        $placement = (object) [
            'qty' => 93,
            'bin' => (object) ['bin_final_code' => 'IN-B8-K4-P9'],
        ];
        $item->setRelation('placements', new Collection([$placement]));
        $putaway->setRelation('items', new Collection([$item]));

        $html = view('inventory::pdf.putaway', [
            'putaway' => $putaway,
            'printedBy' => 'tester',
            'sourceLabel' => 'PO-000001',
        ])->render();

        $this->assertStringContainsString('Laporan Putaway', $html);
        $this->assertStringContainsString('No. Penerimaan', $html);
        $this->assertStringContainsString('Qty<br>Ditetapkan', $html);
        $this->assertStringContainsString('Qty<br>Ditempatkan', $html);
        $this->assertStringContainsString('Qty<br>Sisa', $html);
        $this->assertStringContainsString('IN-B8-K4-P9 (93)', $html);
        $this->assertStringContainsString('>100</td>', $html);
        $this->assertStringContainsString('>93</td>', $html);
        $this->assertStringContainsString('>7</td>', $html);
    }
}
