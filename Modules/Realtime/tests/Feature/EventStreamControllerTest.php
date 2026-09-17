<?php

namespace Modules\Realtime\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Report\Models\ExportJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Tests\TestCase;

class EventStreamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_requires_authentication(): void
    {
        $this->getJson('/api/v1/realtime/stream?export_id=00000000-0000-0000-0000-000000000001')
            ->assertUnauthorized();
    }

    public function test_stream_requires_at_least_one_owned_resource(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/realtime/stream')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Minimal satu resource realtime diperlukan.');
    }

    public function test_stream_rejects_batch_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $owner->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/realtime/stream?bulk_label_batch_id='.$batch->id)
            ->assertForbidden();
    }

    public function test_stream_rejects_export_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $job = ExportJob::create([
            'user_id' => $owner->id,
            'type' => 'test',
            'status' => ExportJob::STATUS_PROCESSING,
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/realtime/stream?export_id='.$job->id)
            ->assertForbidden();
    }
}
