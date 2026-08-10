<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Http\Resources\SalesPaymentResource;
use Modules\Sales\Http\Resources\SalesReturnResource;
use Modules\Sales\Http\Resources\SalesReturnSettlementInvoiceResource;
use Modules\Sales\Http\Resources\SalesReturnSettlementRefundResource;
use Modules\Sales\Http\Resources\SalesReturnSettlementResource;
use Modules\Sales\Http\Resources\SalesSettlementResource;
use Modules\Sales\Models\SalesPayment;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnSettlement;
use Modules\Sales\Models\SalesReturnSettlementInvoice;
use Modules\Sales\Models\SalesReturnSettlementRefund;
use Modules\Sales\Models\SalesSettlement;
use Tests\TestCase;

class SalesResourceParityTest extends TestCase
{
    private function assertParity($model, $resource): array
    {
        $expected = json_decode(json_encode($model->toArray()), true);
        $actual = json_decode(json_encode($resource->resolve()), true);
        $this->assertEquals($expected, $actual);

        return $actual;
    }

    public function test_scalar_parity_for_all_resources(): void
    {
        $cases = [
            [new SalesReturn(['return_number' => 'RTN-1', 'status' => 'REQUESTED', 'refund_amount' => 12000]), SalesReturnResource::class],
            [new SalesReturnSettlement(['settlement_number' => 'STL-1', 'status' => 'DRAFT', 'total_amount' => 5000]), SalesReturnSettlementResource::class],
            [new SalesReturnSettlementInvoice(['invoice_id' => 'x', 'amount' => 5000]), SalesReturnSettlementInvoiceResource::class],
            [new SalesReturnSettlementRefund(['refund_number' => 'RF-1', 'amount' => 5000, 'refund_method' => 'CASH']), SalesReturnSettlementRefundResource::class],
            [new SalesPayment(['payment_number' => 'PAY-1', 'payment_method' => 'CASH', 'amount' => 5000]), SalesPaymentResource::class],
            [new SalesSettlement(['settlement_number' => 'SET-1', 'channel' => 'shopee', 'total_settlement' => 9000]), SalesSettlementResource::class],
        ];

        foreach ($cases as [$model, $resourceClass]) {
            $model->id = '0199aaaa-0000-7000-8000-000000000001';
            $model->created_at = now();
            $model->updated_at = now();

            $this->assertParity($model, new $resourceClass($model));
        }
    }

    public function test_payment_method_relation_overwrites_scalar_on_snake_key(): void
    {
        $payment = new SalesPayment(['payment_number' => 'PAY-1', 'payment_method' => 'CASH', 'amount' => 5000]);
        $payment->id = '0199aaaa-0000-7000-8000-000000000002';
        $payment->created_at = now();
        $payment->updated_at = now();

        $payment->setRelation('paymentMethod', new SalesSettlement(['settlement_number' => 'PM-STANDIN']));

        $actual = $this->assertParity($payment, new SalesPaymentResource($payment));

        $this->assertIsArray($actual['payment_method'], 'payment_method harus jadi objek relasi, bukan string');
    }

    public function test_settlement_relation_uses_snake_case_key(): void
    {
        $settlement = new SalesReturnSettlement(['settlement_number' => 'STL-1', 'status' => 'DRAFT', 'total_amount' => 5000]);
        $settlement->id = '0199aaaa-0000-7000-8000-000000000003';
        $settlement->created_at = now();
        $settlement->updated_at = now();
        $settlement->setRelation('salesReturn', new SalesReturn(['return_number' => 'RTN-9']));

        $actual = $this->assertParity($settlement, new SalesReturnSettlementResource($settlement));

        $this->assertArrayHasKey('sales_return', $actual);
        $this->assertArrayNotHasKey('salesReturn', $actual);
    }
}
