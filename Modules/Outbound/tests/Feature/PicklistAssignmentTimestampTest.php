<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\PicklistService;
use Tests\TestCase;

class PicklistAssignmentTimestampTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_picker_records_the_handover_time(): void
    {
        $assigner = User::factory()->create();
        $picker = User::factory()->create();
        $locationId = (string) Str::uuid();

        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'WH-PICK-TIME',
            'location_name' => 'Gudang Pick Time',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $picklist = Picklist::create([
            'picklist_no' => 'PICK-HANDOVER-TIME',
            'location_id' => $locationId,
            'status' => Picklist::STATUS_DRAFT,
            'created_by' => $assigner->id,
        ]);

        $before = now()->subSecond();
        $assigned = app(PicklistService::class)->assignPicker(
            $picklist->id,
            $picker->id,
            $assigner->id,
        );
        $after = now()->addSecond();

        $this->assertSame((string) $picker->id, (string) $assigned->picker_id);
        $this->assertNotNull($assigned->assigned_at);
        $this->assertTrue($assigned->assigned_at->between($before, $after));
        $this->assertNull($assigned->started_at);
    }
}
