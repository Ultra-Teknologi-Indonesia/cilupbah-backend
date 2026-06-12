<?php

namespace Modules\Finance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;
use Tests\TestCase;

/**
 * Upgrade cashbank (PLAN-JOURNAL.md §1e, U19–U20): detail memakai jurnal NYATA
 * bila ada (journal_detail_id terisi), fallback sintesis untuk data pra-Journal.
 */
class CashbankJournalUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeReceive(): SalesPayment
    {
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-0001',
            'customer_name' => 'PT Pelanggan',
            'location_id' => \Modules\Warehouse\Models\Location::factory()->create()->id,
            'status' => 'unpaid',
            'invoice_date' => now(),
            'total_amount' => 500000,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        return SalesPayment::create([
            'payment_number' => 'REC-0001',
            'sales_invoice_id' => $invoice->id,
            'amount' => 500000,
            'payment_date' => now(),
            'payment_method' => 'transfer',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_cashbank_detail_uses_real_journal_when_available(): void
    {
        $this->seed(ChartOfAccountsSeeder::class); // COA ada → observer membuat jurnal nyata
        $receive = $this->makeReceive();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/receives/{$receive->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.accounts.0.account_name', '1-1001 - Bank');

        // Penanda jurnal NYATA: journal_detail_id terisi (sintesis = null).
        foreach ($response->json('data.accounts') as $line) {
            $this->assertNotNull($line['journal_detail_id']);
        }
    }

    public function test_cashbank_detail_falls_back_to_synthesis_without_journal(): void
    {
        // COA TIDAK di-seed → tidak ada jurnal nyata → fallback sintesis (data lama).
        $receive = $this->makeReceive();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/cashbank/receives/{$receive->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.accounts.0.account_name', '1-1001 - Bank')
            ->assertJsonPath('data.accounts.1.account_name', '1-1100 - Piutang Usaha');

        foreach ($response->json('data.accounts') as $line) {
            $this->assertNull($line['journal_detail_id']); // sintesis
        }
    }
}
