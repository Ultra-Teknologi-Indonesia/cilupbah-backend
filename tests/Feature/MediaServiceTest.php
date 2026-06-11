<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Membuktikan MediaService (add / replace / delete / deleteById / url) bekerja
 * di atas Spatie Media Library, memakai disk yang di-fake (disk-agnostic, tanpa R2 nyata).
 * Diuji lewat model pembawa media generik: Upload (koleksi 'file').
 */
class MediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaService $media;

    protected function setUp(): void
    {
        parent::setUp();

        // Arahkan media library ke disk fake agar tidak menyentuh R2 sungguhan.
        config(['filesystems.disks.media_test' => ['driver' => 'local', 'root' => storage_path('app/media_test')]]);
        config(['media-library.disk_name' => 'media_test']);
        Storage::fake('media_test');

        $this->media = app(MediaService::class);
    }

    private function owner(): Upload
    {
        return Upload::create(['uploaded_by' => null]);
    }

    private function file(string $name = 'foto.jpg'): UploadedFile
    {
        // create() (bukan image()) → tidak butuh ekstensi GD.
        return UploadedFile::fake()->create($name, 10, 'image/jpeg');
    }

    public function test_add_attaches_media_and_stores_file(): void
    {
        $owner = $this->owner();

        $media = $this->media->add($owner, $this->file('foto.jpg'), 'file');

        $this->assertEquals('file', $media->collection_name);
        $this->assertEquals('media_test', $media->disk);
        $this->assertCount(1, $owner->refresh()->getMedia('file'));
        Storage::disk('media_test')->assertExists($media->id . '/' . $media->file_name);
    }

    public function test_replace_keeps_single_item_and_swaps_file(): void
    {
        $owner = $this->owner();

        $first = $this->media->add($owner, $this->file('lama.jpg'), 'file');
        $second = $this->media->replace($owner, $this->file('baru.jpg'), 'file');

        $collection = $owner->refresh()->getMedia('file');
        $this->assertCount(1, $collection);
        $this->assertEquals('baru', $collection->first()->name);

        // File lama sudah dihapus dari disk.
        Storage::disk('media_test')->assertMissing($first->id . '/' . $first->file_name);
        Storage::disk('media_test')->assertExists($second->id . '/' . $second->file_name);
    }

    public function test_single_file_collection_auto_replaces_on_add(): void
    {
        $owner = $this->owner();

        $this->media->add($owner, $this->file('a.jpg'), 'file');
        $this->media->add($owner, $this->file('b.jpg'), 'file');

        // Koleksi 'file' dideklarasikan singleFile() → tetap 1.
        $this->assertCount(1, $owner->refresh()->getMedia('file'));
    }

    public function test_delete_clears_collection(): void
    {
        $owner = $this->owner();
        $this->media->add($owner, $this->file(), 'file');

        $this->media->delete($owner, 'file');

        $this->assertCount(0, $owner->refresh()->getMedia('file'));
    }

    public function test_delete_by_id_only_removes_owned_media(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();

        $mediaA = $this->media->add($ownerA, $this->file('a.jpg'), 'file');
        $mediaB = $this->media->add($ownerB, $this->file('b.jpg'), 'file');

        // ownerA tidak boleh menghapus media milik ownerB.
        $this->assertFalse($this->media->deleteById($ownerA, $mediaB->id));
        $this->assertCount(1, $ownerB->refresh()->getMedia('file'));

        // ownerA boleh menghapus miliknya sendiri.
        $this->assertTrue($this->media->deleteById($ownerA, $mediaA->id));
        $this->assertCount(0, $ownerA->refresh()->getMedia('file'));
    }

    public function test_url_returns_null_when_empty_and_value_when_present(): void
    {
        $owner = $this->owner();

        $this->assertNull($this->media->url($owner, 'file'));

        $this->media->add($owner, $this->file(), 'file');

        $this->assertIsString($this->media->url($owner->refresh(), 'file'));
    }

    public function test_trait_helpers_delegate_to_service(): void
    {
        $owner = $this->owner();

        $owner->uploadMedia($this->file('x.jpg'), 'file');
        $this->assertCount(1, $owner->refresh()->getMedia('file'));

        $owner->replaceMediaItem($this->file('y.jpg'), 'file');
        $this->assertEquals('y', $owner->refresh()->getFirstMedia('file')->name);

        $this->assertIsString($owner->mediaUrl('file'));

        $owner->deleteMedia('file');
        $this->assertCount(0, $owner->refresh()->getMedia('file'));
    }
}
