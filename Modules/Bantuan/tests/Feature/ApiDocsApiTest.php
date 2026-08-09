<?php

namespace Modules\Bantuan\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDocsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/bantuan/api-docs')->assertStatus(401);
    }

    public function test_index_returns_module_catalog(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/bantuan/api-docs')
            ->assertStatus(200)
            ->assertJsonPath('version', '1.0.0')
            ->assertJsonStructure([
                'generated_at',
                'version',
                'totals' => ['modules', 'endpoints', 'undocumented'],
                'modules',
            ]);
    }

    public function test_module_returns_supplier_documentation(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/bantuan/api-docs/supplier')
            ->assertStatus(200)
            ->assertJsonPath('slug', 'supplier')
            ->assertJsonStructure([
                'slug',
                'name',
                'description',
                'endpoints_count',
                'endpoints',
            ]);
    }

    public function test_unknown_module_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/bantuan/api-docs/modul-tidak-ada')
            ->assertStatus(404)
            ->assertJsonPath('message', "Modul 'modul-tidak-ada' tidak ditemukan");
    }
}
