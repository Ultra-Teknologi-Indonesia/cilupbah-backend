<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Tests\TestCase;

class CleanupBulkLabelBatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_expired_files_without_deleting_batch_history(): void
    {
        Storage::fake('documents');
        config()->set('file-retention.export_hours', 24);

        $user = User::factory()->create();

        $oldBatch = BulkShippingLabelBatch::create([
            'user_id' => $user->id,
            'status' => 'ready',
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'merged_pdf_path' => 'bulk-labels/old.pdf',
        ]);
        Storage::disk('documents')->put('bulk-labels/old.pdf', '%PDF-');

        $oldBatch->update(['finished_at' => now()->subHours(25)]);

        $freshBatch = BulkShippingLabelBatch::create([
            'user_id' => $user->id,
            'status' => 'ready',
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'merged_pdf_path' => 'bulk-labels/fresh.pdf',
        ]);
        Storage::disk('documents')->put('bulk-labels/fresh.pdf', '%PDF-');

        $this->artisan('sales:cleanup-bulk-label-batches')
            ->assertSuccessful();

        $this->assertDatabaseHas('bulk_shipping_label_batches', [
            'id' => $oldBatch->id,
            'merged_pdf_path' => null,
        ]);
        $this->assertNotNull($oldBatch->fresh()->file_purged_at);
        $this->assertDatabaseHas('bulk_shipping_label_batches', ['id' => $freshBatch->id]);
        Storage::disk('documents')->assertMissing('bulk-labels/old.pdf');
        Storage::disk('documents')->assertExists('bulk-labels/fresh.pdf');
    }
}
