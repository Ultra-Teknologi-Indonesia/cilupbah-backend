<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\BulkShippingLabelBatch;
use App\Models\User;
use Tests\TestCase;

class CleanupBulkLabelBatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_batches_older_than_threshold(): void
    {
        Storage::fake('documents');

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
        // backdate 25h — well past default 24h retention
        $oldBatch->update(['created_at' => now()->subHours(25)]);

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

        $this->assertDatabaseMissing('bulk_shipping_label_batches', ['id' => $oldBatch->id]);
        $this->assertDatabaseHas('bulk_shipping_label_batches', ['id' => $freshBatch->id]);
        Storage::disk('documents')->assertMissing('bulk-labels/old.pdf');
        Storage::disk('documents')->assertExists('bulk-labels/fresh.pdf');
    }
}
