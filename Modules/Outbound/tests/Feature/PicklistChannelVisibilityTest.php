<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;
use Tests\TestCase;

class PicklistChannelVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_can_monitor_picklist_assigned_to_another_picker_but_mobile_cannot(): void
    {
        $viewer = User::factory()->create();
        $picker = User::factory()->create();
        $permission = Permission::create(['name' => 'view-picking', 'guard_name' => 'web']);
        $viewer->givePermissionTo($permission);

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'WH-CHANNEL-VISIBILITY',
            'location_name' => 'Gudang Channel Visibility',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Picklist::create([
            'picklist_no' => 'PICK-CHANNEL-VISIBILITY',
            'location_id' => $locationId,
            'picker_id' => $picker->id,
            'status' => Picklist::STATUS_DRAFT,
            'created_by' => $viewer->id,
        ]);

        $webList = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/outbound/picklists');
        $webCounts = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/outbound/picklists/counts');

        $webList->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PICK-CHANNEL-VISIBILITY');
        $webCounts->assertOk()->assertJsonPath('data.DRAFT', 1);

        $mobileList = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'MOBILE')
            ->getJson('/api/v1/outbound/picklists');
        $mobileCounts = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'MOBILE')
            ->getJson('/api/v1/outbound/picklists/counts');

        $mobileList->assertOk()->assertJsonPath('meta.total', 0);
        $mobileCounts->assertOk()->assertJsonPath('data.DRAFT', 0);
    }
}
