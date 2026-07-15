<?php

namespace Modules\Warehouse\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view-pengaturan-sistem', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'edit-pengaturan-sistem', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['view-pengaturan-sistem', 'edit-pengaturan-sistem']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    public function test_index_returns_default_profile(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/systemsetting/company')
            ->assertStatus(200)
            ->assertJsonPath('data.legal_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    }

    public function test_can_save_company_profile(): void
    {
        $payload = [
            'legal_name' => 'PT CILUPBAH JAYA',
            'address' => 'Jl. Gudang No. 1, Bandung',
            'city' => 'Bandung',
            'phone' => '+62221234567',
            'email' => 'ops@cilupbah.test',
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/company', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.legal_name', 'PT CILUPBAH JAYA')
            ->assertJsonPath('data.city', 'Bandung');

        $this->assertDatabaseHas('company_profile', ['legal_name' => 'PT CILUPBAH JAYA']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/systemsetting/company')
            ->assertJsonPath('data.legal_name', 'PT CILUPBAH JAYA');
    }

    public function test_legal_name_is_required(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/company', ['legal_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_name']);
    }

    public function test_singleton_never_creates_second_row(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/company', ['legal_name' => 'A'])
            ->assertStatus(200);
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/company', ['legal_name' => 'B'])
            ->assertStatus(200);

        $this->assertDatabaseCount('company_profile', 1);
    }
}
