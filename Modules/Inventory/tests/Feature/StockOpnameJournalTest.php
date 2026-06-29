<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\AutoJournalService;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class StockOpnameJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_positive_signed_value_creates_inventory_debit_gain_credit(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $sourceId = Uuid::uuid7()->toString();

        app(AutoJournalService::class)->forStockOpnameAdjustment(
            'OPN-0001',
            $sourceId,
            now(),
            250000,
        );

        $journal = Journal::with('details.account')
            ->where('source_doc_type', 'stock_opname')
            ->where('source_doc_id', $sourceId)
            ->first();

        $this->assertNotNull($journal);
        $this->assertCount(2, $journal->details);

        $dr = $journal->details->firstWhere('debit', '>', 0);
        $cr = $journal->details->firstWhere('credit', '>', 0);
        $this->assertEquals('1-1200', $dr->account->account_code);
        $this->assertEquals('4-4900', $cr->account->account_code);
    }

    public function test_negative_signed_value_creates_loss_debit_inventory_credit(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $sourceId = Uuid::uuid7()->toString();

        app(AutoJournalService::class)->forStockOpnameAdjustment(
            'OPN-0002',
            $sourceId,
            now(),
            -120000,
        );

        $journal = Journal::with('details.account')
            ->where('source_doc_type', 'stock_opname')
            ->where('source_doc_id', $sourceId)
            ->first();

        $this->assertNotNull($journal);
        $dr = $journal->details->firstWhere('debit', '>', 0);
        $cr = $journal->details->firstWhere('credit', '>', 0);
        $this->assertEquals('5-5900', $dr->account->account_code);
        $this->assertEquals('1-1200', $cr->account->account_code);
        $this->assertEquals('120000.0000', (string) $dr->debit);
    }

    public function test_zero_signed_value_creates_no_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        app(AutoJournalService::class)->forStockOpnameAdjustment(
            'OPN-0003',
            Uuid::uuid7()->toString(),
            now(),
            0,
        );

        $this->assertEquals(0, Journal::where('source_doc_type', 'stock_opname')->count());
    }
}
