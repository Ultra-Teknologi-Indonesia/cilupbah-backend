<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Endpoint media terpusat: POST/PUT/DELETE/GET /api/v1/media/upload.
 * Fokus: upload-replace-delete bekerja, UUID stabil saat replace, dan TIDAK error 500.
 */
class MediaUploadApiTest extends TestCase
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

    private function file(string $name = 'doc.pdf', string $mime = 'application/pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, $mime);
    }

    public function test_upload_returns_uuid_and_url(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/media/upload', ['file' => $this->file('faktur.pdf')]);

        $response->assertStatus(201)
            ->assertJsonStructure(['status', 'message', 'data' => ['uuid', 'url', 'file_name', 'original_name', 'mime_type', 'size']])
            ->assertJsonPath('data.original_name', 'faktur.pdf');

        $this->assertDatabaseCount('uploads', 1);
        $this->assertDatabaseCount('media', 1);

        $uuid = $response->json('data.uuid');
        $media = Media::where('uuid', $uuid)->first();
        Storage::disk('media_test')->assertExists($media->id . '/' . $media->file_name);
    }

    public function test_replace_keeps_same_uuid_and_swaps_file(): void
    {
        $uuid = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/media/upload', ['file' => $this->file('lama.pdf')])
            ->json('data.uuid');

        $oldMedia = Media::where('uuid', $uuid)->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/media/upload/{$uuid}", ['file' => $this->file('baru.png', 'image/png')]);

        $response->assertStatus(200)
            ->assertJsonPath('data.uuid', $uuid)              // UUID TETAP
            ->assertJsonPath('data.original_name', 'baru.png');

        // Tetap 1 media, file lama hilang, file baru ada.
        $this->assertDatabaseCount('media', 1);
        Storage::disk('media_test')->assertMissing($oldMedia->id . '/' . $oldMedia->file_name);

        $newMedia = Media::where('uuid', $uuid)->first();
        Storage::disk('media_test')->assertExists($newMedia->id . '/' . $newMedia->file_name);
    }

    public function test_show_returns_current_url(): void
    {
        $uuid = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/media/upload', ['file' => $this->file()])
            ->json('data.uuid');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/media/upload/{$uuid}")
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonStructure(['data' => ['uuid', 'url', 'file_name']]);
    }

    public function test_delete_removes_file_and_owner(): void
    {
        $uuid = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/media/upload', ['file' => $this->file()])
            ->json('data.uuid');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/media/upload/{$uuid}")
            ->assertStatus(200);

        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('uploads', 0);

        // Sudah terhapus → akses berikutnya 404, bukan 500.
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/media/upload/{$uuid}")
            ->assertStatus(404);
    }

    // ── Guard no-500 ──

    public function test_upload_without_file_returns_422(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/media/upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_nonexistent_uuid_returns_404(): void
    {
        $randomUuid = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/media/upload/{$randomUuid}")
            ->assertStatus(404);
    }

    public function test_non_uuid_param_returns_404_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/media/upload/not-a-uuid')
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/media/upload/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/media/upload', ['file' => $this->file()])
            ->assertStatus(401);
    }
}
