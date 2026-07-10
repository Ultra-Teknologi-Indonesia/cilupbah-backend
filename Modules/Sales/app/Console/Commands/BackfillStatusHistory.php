<?php

namespace Modules\Sales\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;

class BackfillStatusHistory extends Command
{
    protected $signature = 'sales:backfill-status-history
        {--process-actor=cilupbah@ultra-fit.id : Email actor untuk entri PROCESS hasil backfill}
        {--dry-run : Hitung tanpa menulis apa pun}';

    protected $description = 'Backfill riwayat status: CREATED (oleh sistem) untuk order tanpa entri Dibuat, dan PROCESS untuk order yang sudah diserahkan ke gudang';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $processActorEmail = (string) $this->option('process-actor');

        $processActor = User::where('email', $processActorEmail)->first();
        $processActorName = $processActor?->name ?? 'Cilupbah';
        $processActorId = $processActor?->id;

        if (! $processActor) {
            $this->warn("User dengan email {$processActorEmail} tidak ditemukan; entri PROCESS dicatat tanpa actor_id.");
        }

        $createdCount = 0;
        $processCount = 0;

        SalesOrder::query()
            ->select(['id', 'status', 'created_at', 'handed_to_warehouse_at'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use (
                &$createdCount,
                &$processCount,
                $dryRun,
                $processActorEmail,
                $processActorName,
                $processActorId
            ) {
                $ids = $orders->pluck('id')->all();

                $existing = SalesOrderStatusHistory::whereIn('salesorder_id', $ids)
                    ->whereIn('action', ['CREATED', 'PROCESS'])
                    ->get(['salesorder_id', 'action'])
                    ->groupBy('salesorder_id')
                    ->map(fn ($rows) => $rows->pluck('action')->all());

                foreach ($orders as $order) {
                    $actions = $existing->get($order->id, []);

                    if (! in_array('CREATED', $actions, true)) {
                        if (! $dryRun) {
                            SalesOrderStatusHistory::create([
                                'salesorder_id' => $order->id,
                                'action_id'     => '100',
                                'action'        => 'CREATED',
                                'actor_email'   => 'system',
                                'actor_id'      => null,
                                'actor_name'    => 'System',
                                'metadata'      => ['backfill' => true, 'to' => $order->status],
                                'created_at'    => $order->created_at,
                            ]);
                        }
                        $createdCount++;
                    }

                    $isProcessed = $order->handed_to_warehouse_at !== null
                        || in_array($order->status, ['picked', 'packed', 'shipped'], true);

                    if ($isProcessed && ! in_array('PROCESS', $actions, true)) {
                        if (! $dryRun) {
                            SalesOrderStatusHistory::create([
                                'salesorder_id' => $order->id,
                                'action_id'     => '200',
                                'action'        => 'PROCESS',
                                'actor_email'   => $processActorEmail,
                                'actor_id'      => $processActorId,
                                'actor_name'    => $processActorName,
                                'metadata'      => ['backfill' => true, 'to' => 'reserved'],
                                'created_at'    => $order->handed_to_warehouse_at ?? $order->created_at,
                            ]);
                        }
                        $processCount++;
                    }
                }
            });

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}CREATED dibuat: {$createdCount}");
        $this->info("{$prefix}PROCESS dibuat: {$processCount} (actor: {$processActorEmail})");

        return self::SUCCESS;
    }
}
