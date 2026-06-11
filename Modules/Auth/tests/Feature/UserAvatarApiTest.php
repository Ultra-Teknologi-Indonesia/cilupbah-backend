<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integrasi User Avatar via endpoint media terpusat (/media/upload) + referensi avatar_media_id.
 * Fokus: set/replace/remove avatar, link stabil saat file di-replace, dan no-500.
 */
class UserAvatarApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.media_test' => ['driver' => 'local', 'root' => storage_path('app/media_test')]]);
        config(['media-library.disk_name' => 'media_test']);
        Storage::fake('media_test');

        $this->user = User::factory()->create();
    }

    private function file(string $name = 'avatar.png', string $mime = 'image/png'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 50, $mime);
    }

    /** Upload ke endpoint media terpusat, balikan media uuid. */
    private function uploadMedia(string $name = 'avatar.png'): string
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/media/upload', ['file' => $this->file($name)])
            ->json('data.uuid');
    }

    public function test_set_avatar_via_profile(): void
    {
        $uuid = $this->uploadMedia();

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/avatar', ['media_uuid' => $uuid])
            ->assertStatus(200)
            ->assertJsonPath('data.avatar_media_id', $uuid)
            ->assertJsonPath('data.avatar_url', fn ($url) => is_string($url) && $url !== '');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'avatar_media_id' => $uuid,
        ]);

        // Muncul juga di GET /profile.
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.avatar_media_id', $uuid);
    }

    public function test_replace_file_keeps_avatar_link_and_updates_url(): void
    {
        $uuid = $this->uploadMedia('lama.png');

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/avatar', ['media_uuid' => $uuid])
            ->assertStatus(200);

        $urlBefore = $this->user->refresh()->avatar_url;

        // Ganti file pada uuid yang sama lewat endpoint media.
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/media/upload/{$uuid}", ['file' => $this->file('baru.png')])
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $uuid);

        $this->user->refresh();

        // Link tetap (uuid sama), URL berubah ke file baru.
        $this->assertEquals($uuid, $this->user->avatar_media_id);
        $this->assertIsString($this->user->avatar_url);
        $this->assertNotEquals($urlBefore, $this->user->avatar_url);
    }

    public function test_remove_avatar(): void
    {
        $uuid = $this->uploadMedia();
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/avatar', ['media_uuid' => $uuid])
            ->assertStatus(200);

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/avatar', ['media_uuid' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.avatar_media_id', null)
            ->assertJsonPath('data.avatar_url', null);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'avatar_media_id' => null,
        ]);
    }

    public function test_invalid_media_uuid_returns_422(): void
    {
        $randomUuid = (string) Str::uuid();

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/avatar', ['media_uuid' => $randomUuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media_uuid']);
    }

    public function test_non_uuid_media_returns_422_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/avatar', ['media_uuid' => 'not-a-uuid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media_uuid']);
    }

    public function test_admin_can_set_avatar_on_user_update(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $target = User::factory()->create();
        $target->assignRole('picker');

        // Upload sebagai owner.
        $uuid = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/media/upload', ['file' => $this->file()])
            ->json('data.uuid');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}", [
                'name' => 'Target',
                'email' => 'target@example.com',
                'roles' => ['picker'],
                'avatar_media_id' => $uuid,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'avatar_media_id' => $uuid,
        ]);
    }
}
