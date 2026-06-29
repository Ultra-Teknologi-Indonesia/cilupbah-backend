<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\AutoJournalService;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class StockRevaluationJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_positive_delta_debits_inventory_credits_gain(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $sourceId = Uuid::uuid7()->toString();

        app(AutoJournalService::class)->forStockRevaluation(
            'REV-0001',
            $sourceId,
            now(),
            500000,
        );

        $journal = Journal::with('details.account')
            ->where('source_doc_type', 'stock_revaluation')
            ->where('source_doc_id', $sourceId)
            ->first();

        $this->assertNotNull($journal);
        $dr = $journal->details->firstWhere('debit', '>', 0);
        $cr = $journal->details->firstWhere('credit', '>', 0);
        $this->assertEquals('1-1200', $dr->account->account_code);
        $this->assertEquals('4-4900', $cr->account->account_code);
    }

    public function test_negative_delta_debits_loss_credits_inventory(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $sourceId = Uuid::uuid7()->toString();

        app(AutoJournalService::class)->forStockRevaluation(
            'REV-0002',
            $sourceId,
            now(),
            -75000,
        );

        $journal = Journal::with('details.account')
            ->where('source_doc_type', 'stock_revaluation')
            ->where('source_doc_id', $sourceId)
            ->first();

        $this->assertNotNull($journal);
        $dr = $journal->details->firstWhere('debit', '>', 0);
        $cr = $journal->details->firstWhere('credit', '>', 0);
        $this->assertEquals('5-5900', $dr->account->account_code);
        $this->assertEquals('1-1200', $cr->account->account_code);
    }
}
