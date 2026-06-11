<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Membuktikan MediaService (add / replace / delete / deleteById / url) bekerja
 * di atas Spatie Media Library, memakai disk yang di-fake (disk-agnostic, tanpa R2 nyata).
 */
class MediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaService $media;

    protected function setUp(): void
    {
        parent::setUp();

        // Daftarkan disk uji lalu fake-kan, agar tidak menyentuh R2 sungguhan.
        config(['filesystems.disks.media_test' => ['driver' => 'local', 'root' => storage_path('app/media_test')]]);
        config(['media-library.disk_name' => 'media_test']);
        Storage::fake('media_test');

        $this->media = app(MediaService::class);
    }

    private function file(string $name = 'avatar.jpg'): UploadedFile
    {
        // create() (bukan image()) → tidak butuh ekstensi GD.
        return UploadedFile::fake()->create($name, 10, 'image/jpeg');
    }

    public function test_add_attaches_media_and_stores_file(): void
    {
        $user = User::factory()->create();

        $media = $this->media->add($user, $this->file('foto.jpg'), 'avatar');

        $this->assertEquals('avatar', $media->collection_name);
        $this->assertEquals('media_test', $media->disk);
        $this->assertCount(1, $user->refresh()->getMedia('avatar'));
        Storage::disk('media_test')->assertExists($media->id . '/' . $media->file_name);
    }

    public function test_replace_keeps_single_item_and_swaps_file(): void
    {
        $user = User::factory()->create();

        $first = $this->media->add($user, $this->file('lama.jpg'), 'avatar');
        $second = $this->media->replace($user, $this->file('baru.jpg'), 'avatar');

        $collection = $user->refresh()->getMedia('avatar');
        $this->assertCount(1, $collection);
        $this->assertEquals('baru', $collection->first()->name);

        // File lama sudah dihapus dari disk.
        Storage::disk('media_test')->assertMissing($first->id . '/' . $first->file_name);
        Storage::disk('media_test')->assertExists($second->id . '/' . $second->file_name);
    }

    public function test_single_file_collection_auto_replaces_on_add(): void
    {
        $user = User::factory()->create();

        $this->media->add($user, $this->file('a.jpg'), 'avatar');
        $this->media->add($user, $this->file('b.jpg'), 'avatar');

        // Koleksi avatar dideklarasikan singleFile() → tetap 1.
        $this->assertCount(1, $user->refresh()->getMedia('avatar'));
    }

    public function test_delete_clears_collection(): void
    {
        $user = User::factory()->create();
        $this->media->add($user, $this->file(), 'avatar');

        $this->media->delete($user, 'avatar');

        $this->assertCount(0, $user->refresh()->getMedia('avatar'));
    }

    public function test_delete_by_id_only_removes_owned_media(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $mediaA = $this->media->add($userA, $this->file('a.jpg'), 'avatar');
        $mediaB = $this->media->add($userB, $this->file('b.jpg'), 'avatar');

        // userA tidak boleh menghapus media milik userB.
        $this->assertFalse($this->media->deleteById($userA, $mediaB->id));
        $this->assertCount(1, $userB->refresh()->getMedia('avatar'));

        // userA boleh menghapus miliknya sendiri.
        $this->assertTrue($this->media->deleteById($userA, $mediaA->id));
        $this->assertCount(0, $userA->refresh()->getMedia('avatar'));
    }

    public function test_url_returns_null_when_empty_and_value_when_present(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->media->url($user, 'avatar'));

        $this->media->add($user, $this->file(), 'avatar');

        $this->assertIsString($this->media->url($user->refresh(), 'avatar'));
    }

    public function test_trait_helpers_delegate_to_service(): void
    {
        $user = User::factory()->create();

        $user->uploadMedia($this->file('x.jpg'), 'avatar');
        $this->assertCount(1, $user->refresh()->getMedia('avatar'));

        $user->replaceMediaItem($this->file('y.jpg'), 'avatar');
        $this->assertEquals('y', $user->refresh()->getFirstMedia('avatar')->name);

        $this->assertIsString($user->mediaUrl('avatar'));

        $user->deleteMedia('avatar');
        $this->assertCount(0, $user->refresh()->getMedia('avatar'));
    }
}
