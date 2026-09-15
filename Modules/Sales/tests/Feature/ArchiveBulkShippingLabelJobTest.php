<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Jobs\ArchiveBulkShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Tests\TestCase;

class ArchiveBulkShippingLabelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_streams_archive_verifies_size_and_removes_spool_file(): void
    {
        Storage::fake('print_spool');
        Storage::fake('documents');

        $batch = BulkShippingLabelBatch::create([
            'user_id' => User::factory()->create()->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'print_pdf_path' => 'bulk-labels/test.pdf',
            'archive_status' => BulkShippingLabelBatch::ARCHIVE_PENDING,
        ]);
        $bytes = "%PDF-1.7\narchive-test\n";
        Storage::disk('print_spool')->put($batch->print_pdf_path, $bytes);

        (new ArchiveBulkShippingLabelJob($batch->id))->handle();

        Storage::disk('documents')->assertExists('bulk-labels/'.$batch->id.'.pdf');
        Storage::disk('print_spool')->assertMissing($batch->print_pdf_path);
        $fresh = $batch->fresh();
        $this->assertSame(BulkShippingLabelBatch::ARCHIVE_ARCHIVED, $fresh->archive_status);
        $this->assertSame('bulk-labels/'.$batch->id.'.pdf', $fresh->merged_pdf_path);
        $this->assertSame(strlen($bytes), $fresh->archive_pdf_bytes);
        $this->assertNotNull($fresh->archived_at);
        $this->assertNotNull($fresh->archive_checksum);
    }
}
