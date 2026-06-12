<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $user = User::factory()->create(['email' => 'login@example.com']);
        $user->assignRole('picker');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'user' => ['id', 'email', 'roles']]]);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        User::factory()->create(['email' => 'login@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401);
    }

    public function test_login_without_fields_returns_422_not_500(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_with_invalid_email_format_returns_422(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_is_rate_limited_after_10_attempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'brute@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'brute@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
