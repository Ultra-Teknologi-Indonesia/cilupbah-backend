<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Jobs\ProcessPacklistCompleteJob;
use Modules\Outbound\Models\Packlist;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class StatusHistoryDeduplicationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected User $packer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->packer = User::factory()->create([
            'name'  => 'Rizki Packer',
            'email' => 'rizki.test@cilupbah.id',
        ]);
    }

    public function test_packlist_completion_creates_only_single_finish_pack_with_human_packer(): void
    {
        $location = \Modules\Warehouse\Models\Location::first() ?? \Modules\Warehouse\Models\Location::create([
            'location_name' => 'Gudang Utama',
            'location_code' => 'WH-TEST-01',
            'type'          => 'warehouse',
        ]);

        $order = SalesOrder::factory()->create([
            'status' => 'picked',
            'channel_order_no' => 'SO-PACK-TEST-001',
            'salesorder_no' => 'SO-PACK-TEST-001',
            'location_id'   => $location->id,
        ]);

        $packlist = Packlist::create([
            'packlist_no'  => 'PL-TEST-001',
            'location_id'  => $location->id,
            'order_id'     => $order->id,
            'packer_id'    => $this->packer->id,
            'status'       => Packlist::STATUS_COMPLETED,
            'started_at'   => now()->subMinute(),
            'completed_at' => now(),
            'created_by'   => 'system',
        ]);

        // Dispatch background job
        $job = new ProcessPacklistCompleteJob($packlist->id);
        $job->handle(app(SalesOrderService::class));

        $order->refresh();
        $this->assertSame('packed', $order->status);

        // Assert only EXACTLY 1 FINISH_PACK entry exists
        $histories = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', OrderActivityAction::FINISH_PACK)
            ->get();

        $this->assertCount(1, $histories, 'Harus hanya ada tepat 1 entry FINISH_PACK');
        $entry = $histories->first();
        $this->assertSame($this->packer->id, $entry->actor_id);
        $this->assertSame('Rizki Packer', $entry->actor_name);
        $this->assertSame('rizki.test@cilupbah.id', $entry->actor_email);
    }

    public function test_update_order_does_not_create_duplicate_observer_entry(): void
    {
        $order = SalesOrder::factory()->create([
            'status' => 'reserved',
            'channel_order_no' => 'SO-PICK-TEST-002',
            'salesorder_no' => 'SO-PICK-TEST-002',
        ]);

        $service = app(SalesOrderService::class);
        $service->updateOrder($order, ['status' => 'picked'], $this->user);

        $order->refresh();
        $this->assertSame('picked', $order->status);

        $histories = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', OrderActivityAction::FINISH_PICK)
            ->get();

        $this->assertCount(1, $histories, 'Harus hanya ada tepat 1 entry FINISH_PICK');
        $this->assertSame($this->user->email, $histories->first()->actor_email);
    }

    public function test_migration_cleans_duplicate_system_entries_and_backfills(): void
    {
        $location = \Modules\Warehouse\Models\Location::first() ?? \Modules\Warehouse\Models\Location::create([
            'location_name' => 'Gudang Utama',
            'location_code' => 'WH-TEST-01',
            'type'          => 'warehouse',
        ]);

        $order = SalesOrder::factory()->create([
            'status' => 'packed',
            'channel_order_no' => 'SO-CLEAN-003',
            'salesorder_no' => 'SO-CLEAN-003',
            'location_id'   => $location->id,
        ]);

        $packlist = Packlist::create([
            'packlist_no'  => 'PL-TEST-003',
            'location_id'  => $location->id,
            'order_id'     => $order->id,
            'packer_id'    => $this->packer->id,
            'status'       => Packlist::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by'   => 'system',
        ]);

        // Buat duplikat historis: 1 system dan 1 human
        $sysHist = SalesOrderStatusHistory::create([
            'salesorder_id' => $order->id,
            'action'        => OrderActivityAction::FINISH_PACK,
            'action_id'     => '800',
            'actor_name'    => 'System',
            'actor_email'   => 'system',
            'actor_id'      => null,
            'metadata'      => ['from' => 'picked', 'to' => 'packed'],
            'created_at'    => now(),
        ]);

        $humanHist = SalesOrderStatusHistory::create([
            'salesorder_id' => $order->id,
            'action'        => OrderActivityAction::FINISH_PACK,
            'action_id'     => '800',
            'actor_name'    => $this->packer->name,
            'actor_email'   => $this->packer->email,
            'actor_id'      => $this->packer->id,
            'metadata'      => ['from' => 'picked', 'to' => 'packed'],
            'created_at'    => now(),
        ]);

        // Jalankan migration up
        $migration = require base_path('Modules/Sales/database/migrations/2026_08_25_004000_deduplicate_status_histories_and_backfill_packers.php');
        $migration->up();

        // Verifikasi entri system duplikat terhapus dan tersisa hanya entri human
        $this->assertDatabaseMissing('sales_order_status_histories', ['id' => $sysHist->id]);
        $this->assertDatabaseHas('sales_order_status_histories', ['id' => $humanHist->id, 'actor_id' => $this->packer->id]);
    }
}
