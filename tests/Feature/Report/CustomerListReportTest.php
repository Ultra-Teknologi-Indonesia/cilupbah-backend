<?php

namespace Tests\Feature\Report;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Report\Exports\CustomerListExport;
use Modules\Report\Services\CustomerListReportService;
use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;
use Tests\TestCase;

class CustomerListReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view-laporan-penjualan', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('view-laporan-penjualan');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    private function customer(array $attrs = [], string $createdAt = '2026-07-05 10:00:00'): Contact
    {
        $c = Contact::create(array_merge([
            'code'        => 'C-' . fake()->unique()->numerify('######'),
            'name'        => 'Emalia Putri',
            'email'       => 'emalia@example.test',
            'phone'       => '08123456789',
            'address'     => 'Jl. Mawar 1',
            'city'        => 'Kota Jakarta Utara',
            'province'    => 'DKI Jakarta',
            'postal_code' => '14360',
            'nationality' => 'WNI',
            'source'      => 'shopee',
            'type'        => Contact::TYPE_CUSTOMER,
            'status'      => Contact::STATUS_ACTIVE,
        ], $attrs));
        $c->forceFill(['created_at' => $createdAt])->save();

        return $c;
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/sales/customer/export')->assertStatus(401);
    }

    public function test_export_endpoint_returns_xlsx(): void
    {
        $this->customer();

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/reports/sales/customer/export?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Daftar-Pelanggan', $response->headers->get('content-disposition'));
    }

    public function test_maps_12_columns_and_category(): void
    {
        $cat = ContactCategory::create(['name' => 'Reseller']);
        $this->customer(['category_id' => $cat->id]);

        $query = app(CustomerListReportService::class)->query([]);
        $cells = (new CustomerListExport($query))->map($query->get()->first());

        $this->assertCount(12, $cells);
        $this->assertSame('Emalia Putri', $cells[0]);          
        $this->assertSame('emalia@example.test', $cells[1]);    
        $this->assertSame('08123456789', $cells[2]);           
        $this->assertSame('Kota Jakarta Utara', $cells[4]);    
        $this->assertSame('WNI', $cells[8]);                   
        $this->assertSame('shopee', $cells[10]);               
        $this->assertSame('Reseller', $cells[11]);             
    }

    public function test_only_customers_within_date_range(): void
    {
        $inRange = $this->customer(['name' => 'A'], '2026-07-10 10:00:00');
        $this->customer(['name' => 'B'], '2026-06-01 10:00:00');

        $this->customer(['name' => 'Supplier X', 'type' => Contact::TYPE_SUPPLIER], '2026-07-10 10:00:00');

        $rows = app(CustomerListReportService::class)
            ->query(['from' => '2026-07-01', 'to' => '2026-07-31'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($inRange->id, $rows->first()->id);
    }
}
