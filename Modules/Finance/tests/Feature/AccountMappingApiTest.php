<?php

namespace Modules\Finance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Journal;
use Modules\Sales\Models\SalesInvoice;
use Tests\TestCase;

class AccountMappingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/systemsetting/account-mapping')->assertStatus(401);
        $this->postJson('/api/v1/systemsetting/account-mapping', [])->assertStatus(401);
    }

    public function test_index_returns_all_keys_with_defaults(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/systemsetting/account-mapping');

        $response->assertStatus(200)->assertJsonCount(6, 'data');

        $byKey = collect($response->json('data'))->keyBy('key');
        $this->assertEquals('4-4000', $byKey['sales_revenue']['account']['code']);
        $this->assertFalse($byKey['sales_revenue']['configured']);
        $this->assertEquals('1-1100', $byKey['accounts_receivable']['account']['code']);
        $this->assertEquals('5-5000', $byKey['cogs']['account']['code']);
    }

    public function test_store_rejects_invalid_key_and_account(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/account-mapping', [
                'mappings' => [['key' => 'ngawur', 'account_id' => null]],
            ])->assertStatus(422);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/account-mapping', [
                'mappings' => [['key' => 'sales_revenue', 'account_id' => (string) \Illuminate\Support\Str::uuid7()]],
            ])->assertStatus(422);
    }

    public function test_store_persists_mapping(): void
    {
        $custom = Account::create([
            'account_code' => '4-4099',
            'account_name' => 'Pendapatan Custom',
            'account_type' => 'revenue',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/account-mapping', [
                'mappings' => [['key' => 'sales_revenue', 'account_id' => $custom->id]],
            ]);

        $response->assertStatus(200);
        $byKey = collect($response->json('data'))->keyBy('key');
        $this->assertTrue($byKey['sales_revenue']['configured']);
        $this->assertEquals('4-4099', $byKey['sales_revenue']['account']['code']);

        $this->assertDatabaseHas('account_mappings', ['key' => 'sales_revenue', 'account_id' => $custom->id]);
    }

    public function test_auto_journal_uses_mapped_account(): void
    {
        $custom = Account::create([
            'account_code' => '1-1199',
            'account_name' => 'Piutang Custom',
            'account_type' => 'asset',
            'is_active' => true,
        ]);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/systemsetting/account-mapping', [
            'mappings' => [['key' => 'accounts_receivable', 'account_id' => $custom->id]],
        ])->assertStatus(200);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MAP-1',
            'customer_name' => 'PT Map',
            'location_id' => \Modules\Warehouse\Models\Location::factory()->create()->id,
            'status' => 'unpaid',
            'invoice_date' => now(),
            'total_amount' => 500000,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        $journal = Journal::with('details.account')
            ->where('source_doc_type', 'sales_invoice')
            ->where('source_doc_id', $invoice->id)
            ->first();

        $this->assertNotNull($journal);
        $debit = $journal->details->firstWhere('debit', '>', 0);
        $this->assertEquals('1-1199', $debit->account->account_code);
    }
}
