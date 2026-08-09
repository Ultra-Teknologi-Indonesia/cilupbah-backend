<?php

namespace Modules\Supplier\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createPrivilegedUser();
    }

    private function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'code' => 'SUP-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'PT Contoh Pemasok',
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/suppliers')->assertStatus(401);
    }

    public function test_index_lists_suppliers(): void
    {
        $this->makeSupplier(['name' => 'PT Alfa']);
        $this->makeSupplier(['name' => 'PT Beta']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_store_creates_supplier_and_auto_generates_code(): void
    {
        $payload = [
            'name'  => 'PT Maju Jaya',
            'email' => 'info@majujaya.test',
            'phone' => '+628123456789',
            'city'  => 'Jakarta',
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/suppliers', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'PT Maju Jaya');

        $this->assertDatabaseHas('suppliers', [
            'name'  => 'PT Maju Jaya',
            'email' => 'info@majujaya.test',
            'city'  => 'Jakarta',
        ]);
    }

    public function test_store_requires_name(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/suppliers', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_invalid_phone_format(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/suppliers', [
                'name'  => 'PT Nomor Salah',
                'phone' => '08123456789', 
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_show_returns_supplier_detail(): void
    {
        $supplier = $this->makeSupplier(['name' => 'PT Detail']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/suppliers/{$supplier->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'PT Detail');
    }
}
