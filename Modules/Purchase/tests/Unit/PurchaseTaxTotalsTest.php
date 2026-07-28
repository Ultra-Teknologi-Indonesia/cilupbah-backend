<?php

namespace Modules\Purchase\Tests\Unit;

use Modules\Purchase\Services\PurchaseBillService;
use Modules\Purchase\Services\PurchaseOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PurchaseTaxTotalsTest extends TestCase
{
    private array $items = [
        ['unit_price' => 100000, 'qty' => 1, 'disc' => 0, 'tax_amount' => 10000],
    ];

    private function totals(string $class, bool $isTaxIncluded): array
    {

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
