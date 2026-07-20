<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Tax\Database\Seeders\TaxDatabaseSeeder;
use Tests\TestCase;

class ProductMasterDataLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createPrivilegedUser());

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(TaxDatabaseSeeder::class);
    }

    public function test_sales_taxes_returns_seeded_taxes(): void
    {
        $this->getJson('/api/v1/products/master-data/sales-taxes')
            ->assertOk()
            ->assertJsonFragment(['name' => 'PPN 11%'])
            ->assertJsonFragment(['name' => 'No Tax']);
    }

    public function test_purchase_taxes_returns_seeded_taxes(): void
    {
        $this->getJson('/api/v1/products/master-data/purchase-taxes')
            ->assertOk()
            ->assertJsonFragment(['name' => 'PPN 12%']);
    }

    public function test_sales_accounts_returns_only_revenue(): void
    {
        $this->getJson('/api/v1/products/master-data/sales-accounts')
            ->assertOk()
            ->assertJsonFragment(['account_code' => '4-4000'])
            ->assertJsonMissing(['account_code' => '1-1200'])  
            ->assertJsonMissing(['account_code' => '5-5000']); 
    }

    public function test_sales_return_accounts_returns_revenue(): void
    {
        $this->getJson('/api/v1/products/master-data/sales-return-accounts')
            ->assertOk()
            ->assertJsonFragment(['account_code' => '4-4200']);
    }

    public function test_inventory_accounts_returns_only_asset(): void
    {
        $this->getJson('/api/v1/products/master-data/inventory-accounts')
            ->assertOk()
            ->assertJsonFragment(['account_code' => '1-1200'])
            ->assertJsonMissing(['account_code' => '4-4000']);
    }

    public function test_cogs_accounts_returns_5_5000(): void
    {
        $this->getJson('/api/v1/products/master-data/cogs-accounts')
            ->assertOk()
            ->assertJsonFragment(['account_code' => '5-5000'])
            ->assertJsonMissing(['account_code' => '4-4000']);
    }
}
