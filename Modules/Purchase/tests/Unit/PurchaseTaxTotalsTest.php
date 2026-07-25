<?php

namespace Modules\Purchase\Tests\Unit;

use Modules\Purchase\Services\PurchaseBillService;
use Modules\Purchase\Services\PurchaseOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Mengunci perilaku is_tax_included: saat harga sudah termasuk pajak, pajak
 * TIDAK ditambahkan lagi ke total (dulu selalu ditambah → total overstated).
 */
class PurchaseTaxTotalsTest extends TestCase
{
    private array $items = [
        ['unit_price' => 100000, 'qty' => 1, 'disc' => 0, 'tax_amount' => 10000],
    ];

    private function totals(string $class, bool $isTaxIncluded): array
    {
        // calculateTotals murni aritmetika → buat instance tanpa konstruktor (tanpa dependency).
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($service, 'calculateTotals');
        $m->setAccessible(true);

        return $m->invoke($service, $this->items, $isTaxIncluded);
    }

    public function test_po_tax_exclusive_adds_tax(): void
    {
        $t = $this->totals(PurchaseOrderService::class, false);
        $this->assertSame(110000.0, (float) $t['total_amount']);
        $this->assertSame(10000.0, (float) $t['total_tax']);
    }

    public function test_po_tax_inclusive_does_not_add_tax(): void
    {
        $t = $this->totals(PurchaseOrderService::class, true);
        // harga 100.000 sudah termasuk 10.000 pajak → total tetap 100.000, bukan 110.000
        $this->assertSame(100000.0, (float) $t['total_amount']);
        $this->assertSame(10000.0, (float) $t['total_tax']);
    }

    public function test_bill_tax_inclusive_does_not_add_tax(): void
    {
        $t = $this->totals(PurchaseBillService::class, true);
        $this->assertSame(100000.0, (float) $t['total_amount']);
    }

    public function test_bill_tax_exclusive_adds_tax(): void
    {
        $t = $this->totals(PurchaseBillService::class, false);
        $this->assertSame(110000.0, (float) $t['total_amount']);
    }
}
