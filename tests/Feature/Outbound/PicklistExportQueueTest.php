<?php

namespace Tests\Feature\Outbound;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\PicklistPdfExportService;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Models\ExportJob;
use Modules\Report\Services\ExportManager;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class PicklistExportQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Picklist $picklist;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = $this->createPrivilegedUser();
        $location = Location::create([
            'location_code' => 'PCK-EXPORT',
            'location_name' => 'Gudang Export',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $this->picklist = Picklist::create([
            'picklist_no' => 'PICK-EXPORT-001',
            'location_id' => $location->id,
            'status' => Picklist::STATUS_DRAFT,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_pdf_dan_excel_diantrekan_dan_dimiliki_user(): void
    {
        $pdf = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/outbound/picklists/{$this->picklist->id}/pdf");

        $pdf->assertStatus(202)
            ->assertJsonPath('data.status', ExportJob::STATUS_QUEUED);

        $pdfJobId = $pdf->json('data.export_id');
        $this->assertDatabaseHas('export_jobs', [
            'id' => $pdfJobId,
            'user_id' => $this->user->id,
            'type' => 'picklist-pdf',
            'status' => ExportJob::STATUS_QUEUED,
        ]);

        $excel = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/outbound/picklists/{$this->picklist->id}/xlsx");

        $excel->assertStatus(202)
            ->assertJsonPath('data.status', ExportJob::STATUS_QUEUED);

        $this->assertDatabaseHas('export_jobs', [
            'id' => $excel->json('data.export_id'),
            'user_id' => $this->user->id,
            'type' => 'picklist-detail',
            'status' => ExportJob::STATUS_QUEUED,
        ]);

        Queue::assertPushed(RunExportJob::class, 2);
    }

    public function test_worker_builder_pdf_dan_query_excel_valid(): void
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'picklist-test-');
        $this->assertNotFalse($pdfPath);

        try {
            app(PicklistPdfExportService::class)->write($this->picklist->id, $pdfPath);

            $this->assertStringStartsWith('%PDF', (string) file_get_contents($pdfPath));

            $export = app(ExportManager::class)->build('picklist-detail', [
                'picklist_id' => $this->picklist->id,
            ]);

            $this->assertSame(0, $export->query()->count());
        } finally {
            @unlink($pdfPath);
        }
    }
}
