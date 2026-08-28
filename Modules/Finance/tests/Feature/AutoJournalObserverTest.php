<?php

namespace Modules\Finance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\AutoJournalService;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;
use Modules\Supplier\Models\Contact;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class AutoJournalObserverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    private function makeInvoice(float $amount = 500000): SalesInvoice
    {
        return SalesInvoice::create([
            'invoice_number' => 'INV-'.fake()->unique()->numerify('####'),
            'customer_name' => 'PT Pelanggan',
            'location_id' => Location::factory()->create()->id,
            'status' => SalesInvoice::STATUS_OPEN,
            'invoice_date' => now(),
            'total_amount' => $amount,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeBill(float $amount = 750000): PurchaseBill
    {
        $supplier = Contact::create([
            'code' => 'SUP-'.fake()->unique()->numerify('###'),
            'name' => 'CV Supplier',
            'type' => Contact::TYPE_SUPPLIER,
            'status' => Contact::STATUS_ACTIVE,
        ]);

        return PurchaseBill::create([
            'bill_number' => 'BILL-'.fake()->unique()->numerify('####'),
            'contact_id' => $supplier->id,
            'location_id' => Location::factory()->create()->id,
            'status' => PurchaseBill::STATUS_OPEN,
            'bill_date' => now(),
            'total_amount' => $amount,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    private function assertJournal(string $sourceType, string $sourceId, string $drCode, string $crCode, string $amount): Journal
    {
        $journal = Journal::with('details.account')
            ->where('source_doc_type', $sourceType)
            ->where('source_doc_id', $sourceId)
            ->first();

        $this->assertNotNull($journal, "Jurnal {$sourceType} tidak terbentuk.");
        $this->assertNull($journal->journal_type);
        $this->assertCount(2, $journal->details);

        $dr = $journal->details->firstWhere('debit', '>', 0);
        $cr = $journal->details->firstWhere('credit', '>', 0);
        $this->assertEquals($drCode, $dr->account->account_code);
        $this->assertEquals($crCode, $cr->account->account_code);
        $this->assertEquals($amount, (string) $dr->debit);
        $this->assertEquals((string) $dr->debit, (string) $cr->credit, 'Jurnal harus seimbang.');

        return $journal;
    }

    public function test_sales_invoice_creates_receivable_revenue_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $invoice = $this->makeInvoice();

        $journal = $this->assertJournal('sales_invoice', $invoice->id, '1-1100', '4-4000', '500000.0000');
        $this->assertEquals($invoice->invoice_number, $journal->source_doc_no);
        $this->assertStringStartsWith('GJ-', $journal->journal_no);
    }

    public function test_sales_payment_creates_bank_receivable_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $invoice = $this->makeInvoice();
        $payment = SalesPayment::create([
            'payment_number' => 'REC-0001',
            'sales_invoice_id' => $invoice->id,
            'amount' => 500000,
            'payment_date' => now(),
            'payment_method' => 'transfer',
            'created_by' => $this->user->id,
        ]);

        $this->assertJournal('sales_payment', $payment->id, '1-1001', '1-1100', '500000.0000');
    }

    public function test_purchase_bill_creates_inventory_payable_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $bill = $this->makeBill();

        $this->assertJournal('purchase_bill', $bill->id, '1-1200', '2-2000', '750000.0000');
    }

    public function test_purchase_payment_creates_payable_cash_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $bill = $this->makeBill();
        $payment = PurchasePayment::create([
            'payment_number' => 'PAY-0001',
            'purchase_bill_id' => $bill->id,
            'amount' => 750000,
            'payment_date' => now(),
            'payment_method' => 'cash',
            'created_by' => $this->user->id,
        ]);

        $this->assertJournal('purchase_payment', $payment->id, '2-2000', '1-1000', '750000.0000');
    }

    public function test_same_source_document_never_creates_duplicate_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $invoice = $this->makeInvoice();

        app(AutoJournalService::class)->forSalesInvoice($invoice);
        app(AutoJournalService::class)->forSalesInvoice($invoice);

        $this->assertEquals(1, Journal::where('source_doc_id', $invoice->id)->count());
    }

    public function test_business_transaction_succeeds_even_without_coa(): void
    {

        $invoice = $this->makeInvoice();

        $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->id]);
        $this->assertEquals(0, Journal::count());
    }

    public function test_zero_amount_document_creates_no_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->makeInvoice(0);

        $this->assertEquals(0, Journal::count());
    }

    public function test_journal_numbering_sequential_across_sources(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->makeInvoice();
        $this->makeBill();

        $numbers = Journal::orderBy('journal_no')->pluck('journal_no')->all();
        $this->assertEquals(['GJ-0000001', 'GJ-0000002'], $numbers);
    }
}
