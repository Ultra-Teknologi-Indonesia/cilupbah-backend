<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Services\AuthService;
use Tests\TestCase;

class RefreshTokenRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function login(): array
    {
        $user = User::factory()->create(['email' => 'refresh@example.com']);
        $user->assignRole('picker');

        return $this->postJson('/api/v1/auth/login', [
            'email' => 'refresh@example.com',
            'password' => 'password',
        ])->assertStatus(200)->json('data');
    }

    private function refreshWith(string $refreshToken): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$refreshToken])
            ->postJson('/api/v1/auth/refresh');
    }

    public function test_refresh_issues_a_new_pair(): void
    {
        $login = $this->login();

        $new = $this->refreshWith($login['refresh_token'])->assertStatus(200)->json('data');

        $this->assertNotEmpty($new['access_token']);
        $this->assertNotEmpty($new['refresh_token']);
        $this->assertNotSame($login['refresh_token'], $new['refresh_token']);
        $this->assertNotSame($login['access_token'], $new['access_token']);
    }

    public function test_reusing_old_refresh_token_within_grace_returns_same_pair(): void
    {
        $login = $this->login();

        $first = $this->refreshWith($login['refresh_token'])->assertStatus(200)->json('data');

        $second = $this->refreshWith($login['refresh_token'])->assertStatus(200)->json('data');

        $this->assertSame($first['access_token'], $second['access_token']);
        $this->assertSame($first['refresh_token'], $second['refresh_token']);
    }

    public function test_rotation_shortens_old_refresh_token_to_grace_window(): void
    {
        $login = $this->login();
        $oldId = explode('|', $login['refresh_token'])[0];

        $before = Carbon::parse(
            DB::table('personal_access_tokens')->where('id', $oldId)->value('expires_at'),
        );
        $this->assertTrue($before->greaterThan(now()->addDay()), 'Refresh token awal berumur panjang.');

        $this->refreshWith($login['refresh_token'])->assertStatus(200);

        $after = Carbon::parse(
            DB::table('personal_access_tokens')->where('id', $oldId)->value('expires_at'),
        );
        $this->assertTrue(
            $after->lessThanOrEqualTo(now()->addSeconds(AuthService::REFRESH_REUSE_GRACE_SECONDS + 5)),
            'Token lama harus dipersingkat ke jendela grace.',
        );
    }

    public function test_expired_old_refresh_token_is_rejected(): void
    {
        $login = $this->login();

        $this->refreshWith($login['refresh_token'])->assertStatus(200);

        $oldId = explode('|', $login['refresh_token'])[0];
        DB::table('personal_access_tokens')->where('id', $oldId)
            ->update(['expires_at' => now()->subMinute()]);

        $this->app['auth']->forgetGuards();

        $this->refreshWith($login['refresh_token'])->assertStatus(401);
    }

    public function test_child_refresh_token_can_rotate_again(): void
    {
        $login = $this->login();

        $rotated = $this->refreshWith($login['refresh_token'])->assertStatus(200)->json('data');

        $this->refreshWith($rotated['refresh_token'])->assertStatus(200);
    }
}
