<?php

namespace Modules\Finance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Services\CashbankService;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * Cash & Bank (tracker 45-48): view read-only setara Jubelio getPayments/getReceives.
 * receives = SalesPayment (uang masuk), payments = PurchasePayment (uang keluar).
 */
class CashbankApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ==================== Helpers ====================

    private function makeReceive(array $override = []): SalesPayment
    {
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-' . fake()->unique()->numerify('####'),
            'customer_name' => 'PT Pelanggan Jaya',
            'location_id' => \Modules\Warehouse\Models\Location::factory()->create()->id,
            'status' => 'unpaid',
            'invoice_date' => now(),
            'total_amount' => 500000,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        return SalesPayment::create(array_merge([
            'payment_number' => 'REC-' . fake()->unique()->numerify('######'),
            'sales_invoice_id' => $invoice->id,
            'amount' => 500000,
            'payment_date' => now(),
            'payment_method' => 'transfer',
            'created_by' => $this->user->id,
        ], $override));
    }

    private function makePayment(array $override = []): PurchasePayment
    {
        $supplier = Supplier::create(['code' => 'SUP-' . fake()->unique()->numerify('###'), 'name' => 'CV Supplier Makmur']);
        $location = \Modules\Warehouse\Models\Location::factory()->create();
        $bill = PurchaseBill::create([
            'bill_number' => 'BILL-' . fake()->unique()->numerify('####'),
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'status' => 'unpaid',
            'bill_date' => now(),
            'total_amount' => 750000,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        return PurchasePayment::create(array_merge([
            'payment_number' => 'PAY-' . fake()->unique()->numerify('######'),
            'purchase_bill_id' => $bill->id,
            'amount' => 750000,
            'payment_date' => now(),
            'payment_method' => 'cash',
            'created_by' => $this->user->id,
        ], $override));
    }

    // ==================== Listing ====================

    public function test_receives_lists_sales_payments_with_jubelio_shape(): void
    {
        $receive = $this->makeReceive();

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/cashbank/receives');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data', 'meta' => ['total', 'per_page']])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doc_type', 'Penerimaan')
            ->assertJsonPath('data.0.payment_no', $receive->payment_number)
            ->assertJsonPath('data.0.contact_name', 'PT Pelanggan Jaya')
            ->assertJsonPath('data.0.account_name', '1-1001 - Bank') // transfer → Bank
            ->assertJsonPath('data.0.amount', '500000.00');
    }

    public function test_payments_lists_purchase_payments(): void
    {
        $this->makePayment();

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/cashbank/payments');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doc_type', 'Pembayaran')
            ->assertJsonPath('data.0.contact_name', 'CV Supplier Makmur')
            ->assertJsonPath('data.0.account_name', '1-1000 - Kas'); // cash → Kas
    }

    public function test_receives_paginates_ten_per_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->makeReceive();
        }

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/cashbank/receives')
            ->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12);
    }

    public function test_date_filter_narrows_results(): void
    {
        $this->makeReceive(['payment_date' => '2026-06-01']);
        $this->makeReceive(['payment_date' => '2026-06-10']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cashbank/receives?transactionDateFrom=2026-06-05&transactionDateTo=2026-06-30')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_date_filter_returns_422_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cashbank/receives?transactionDateFrom=bukan-tanggal')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transactionDateFrom']);
    }

    // ==================== Detail + jurnal sintetis ====================

    public function test_receive_detail_has_balanced_synthesized_journal(): void
    {
        $receive = $this->makeReceive();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/receives/{$receive->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.payment_type', 'Penerimaan')
            ->assertJsonPath('data.cashbank_account_name', '1-1001 - Bank')
            ->assertJsonCount(2, 'data.accounts')
            // Dr Kas/Bank — Cr Piutang, seimbang.
            ->assertJsonPath('data.accounts.0.account_name', '1-1001 - Bank')
            ->assertJsonPath('data.accounts.0.debit', '500000.0000')
            ->assertJsonPath('data.accounts.0.credit', '0.0000')
            ->assertJsonPath('data.accounts.1.account_name', '1-1100 - Piutang Usaha')
            ->assertJsonPath('data.accounts.1.credit', '500000.0000');

        $accounts = $response->json('data.accounts');
        $this->assertEquals(
            array_sum(array_column($accounts, 'debit')),
            array_sum(array_column($accounts, 'credit')),
            'Jurnal sintetis harus seimbang (total debit = total kredit).'
        );
    }

    public function test_payment_detail_journal_debits_payable_credits_cash(): void
    {
        $payment = $this->makePayment();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/payments/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.payment_type', 'Pembayaran')
            ->assertJsonPath('data.accounts.0.account_name', '2-2000 - Hutang Usaha')
            ->assertJsonPath('data.accounts.0.debit', '750000.0000')
            ->assertJsonPath('data.accounts.1.account_name', '1-1000 - Kas')
            ->assertJsonPath('data.accounts.1.credit', '750000.0000');
    }

    // ==================== Use case edge ====================

    public function test_unknown_payment_method_falls_back_to_kas(): void
    {
        $receive = $this->makeReceive(['payment_method' => 'qris-aneh']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/receives/{$receive->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.cashbank_account_name', '1-1000 - Kas');
    }

    public function test_null_relations_are_handled_without_error(): void
    {
        // Defense-in-depth: relasi tak termuat/null tidak boleh meledak
        // (FK restrictOnDelete mencegah orphan nyata, ini jaring pengaman).
        $payment = new PurchasePayment([
            'payment_number' => 'PAY-X',
            'amount' => 1000,
            'payment_method' => 'cash',
        ]);

        $item = app(CashbankService::class)->mapItem($payment);

        $this->assertNull($item['contact_id']);
        $this->assertNull($item['contact_name']);
        $this->assertEquals('Pembayaran', $item['doc_type']);
    }

    // ==================== Guard no-500 ====================

    public function test_non_uuid_id_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cashbank/receives/bukan-uuid')
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cashbank/payments/123')
            ->assertStatus(404);
    }

    public function test_unknown_uuid_returns_404(): void
    {
        $ghost = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/receives/{$ghost}")
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/payments/{$ghost}")
            ->assertStatus(404);
    }

    public function test_wrong_type_id_returns_404_not_cross_leak(): void
    {
        // id PurchasePayment dipakai di endpoint receives → 404 (tidak bocor lintas tipe).
        $payment = $this->makePayment();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/receives/{$payment->id}")
            ->assertStatus(404);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/cashbank/receives')->assertStatus(401);
        $this->getJson('/api/v1/cashbank/payments')->assertStatus(401);
    }

    public function test_empty_data_returns_empty_list_not_error(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cashbank/payments')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }
}
