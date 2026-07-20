<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Journal;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesReturnSettlement;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class SalesReturnSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->location = Location::factory()->create();

        $category = Category::create(['name' => 'Cat Ret', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Produk Ret', 'status' => 'master', 'is_active' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'RET-1', 'sell_price' => 1000, 'is_active' => true]);
    }

    private function setSetting(array $data): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/sales-return-setting', $data)
            ->assertStatus(200);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/systemsetting/sales-return-setting')->assertStatus(401);
    }

    public function test_index_returns_defaults(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/systemsetting/sales-return-setting')
            ->assertStatus(200)
            ->assertJsonPath('data.auto_accept', false)
            ->assertJsonPath('data.allowed_conditions', ['GOOD', 'DAMAGE']);
    }

    public function test_store_persists_and_validates(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/sales-return-setting', [
                'auto_accept' => true,
                'default_restock_location_id' => $this->location->id,
                'allowed_conditions' => ['GOOD'],
                'return_validity_days' => 30,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.auto_accept', true)
            ->assertJsonPath('data.default_restock_location_id', $this->location->id);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/sales-return-setting', ['return_validity_days' => 0])
            ->assertStatus(422);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/sales-return-setting', ['default_restock_location_id' => 'not-a-uuid'])
            ->assertStatus(422);
    }

    public function test_allowed_conditions_enforced_on_return_create(): void
    {
        $this->setSetting(['allowed_conditions' => ['GOOD']]);

        $payload = fn (string $cond) => [
            'location_id' => $this->location->id,
            'created_by' => 'tester',
            'items' => [['item_id' => $this->variant->id, 'qty' => 1, 'condition' => $cond]],
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/returns', $payload('DAMAGE'))
            ->assertStatus(422);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/returns', $payload('GOOD'))
            ->assertStatus(201);
    }

    public function test_refund_method_allowlist_enforced(): void
    {
        $this->setSetting(['allowed_refund_methods' => ['cash']]);
        $settlement = $this->makeSettlement();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/return-settlements/refunds', [
                'settlement_id' => $settlement->id,
                'refund_number' => 'RF-1',
                'amount' => 1000,
                'refund_method' => 'paypal',
                'refund_date' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_refund_creates_return_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $settlement = $this->makeSettlement();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/return-settlements/refunds', [
                'settlement_id' => $settlement->id,
                'refund_number' => 'RF-2',
                'amount' => 250000,
                'refund_method' => 'cash',
                'refund_date' => now()->toDateString(),
            ])
            ->assertStatus(201);

        $journal = Journal::with('details.account')
            ->where('source_doc_type', 'sales_return_refund')
            ->first();

        $this->assertNotNull($journal);
        $debit = $journal->details->firstWhere('debit', '>', 0);
        $credit = $journal->details->firstWhere('credit', '>', 0);
        $this->assertEquals('4-4200', $debit->account->account_code);
        $this->assertEquals('1-1000', $credit->account->account_code);
    }

    private function makeSettlement(): SalesReturnSettlement
    {
        return SalesReturnSettlement::create([
            'settlement_number' => 'STL-' . fake()->unique()->numerify('####'),
            'status' => 'DRAFT',
            'total_amount' => 0,
            'created_by' => 'tester',
        ]);
    }
}
