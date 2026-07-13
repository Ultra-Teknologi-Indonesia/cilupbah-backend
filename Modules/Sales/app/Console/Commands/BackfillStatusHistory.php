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

        $counts = [
            'CREATED'     => 0,
            'PROCESS'     => 0,
            'FINISH_PICK' => 0,
            'FINISH_PACK' => 0,
            'SHIPPED'     => 0,
            'COMPLETED'   => 0,
            'CANCELLED'   => 0,
        ];

        $trackedActions = array_keys($counts);

        SalesOrder::query()
            ->select([
                'id',
                'status',
                'created_at',
                'updated_at',
                'handed_to_warehouse_at',
                'is_canceled',
                'cancel_reason',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use (
                &$counts,
                $dryRun,
                $processActorEmail,
                $processActorName,
                $processActorId,
                $trackedActions
            ) {
                $ids = $orders->pluck('id')->all();

                $existing = SalesOrderStatusHistory::whereIn('salesorder_id', $ids)
                    ->whereIn('action', $trackedActions)
                    ->get(['salesorder_id', 'action'])
                    ->groupBy('salesorder_id')
                    ->map(fn ($rows) => $rows->pluck('action')->all());

                foreach ($orders as $order) {
                    $actions = $existing->get($order->id, []);
                    $timestamp = $order->handed_to_warehouse_at ?? $order->updated_at ?? $order->created_at;

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
                        $counts['CREATED']++;
                    }

                    $reachedProcess = $order->handed_to_warehouse_at !== null
                        || in_array($order->status, ['reserved', 'picked', 'packed', 'shipped'], true);

                    if ($reachedProcess && ! in_array('PROCESS', $actions, true)) {
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
                        $counts['PROCESS']++;
                    }

                    $backfillPhases = [
                        ['action' => 'FINISH_PICK', 'action_id' => '600', 'from' => 'reserved', 'to' => 'picked',  'reachedStatuses' => ['picked', 'packed', 'shipped']],
                        ['action' => 'FINISH_PACK', 'action_id' => '800', 'from' => 'picked',   'to' => 'packed',  'reachedStatuses' => ['packed', 'shipped']],
                        ['action' => 'SHIPPED',     'action_id' => '999', 'from' => 'packed',   'to' => 'shipped', 'reachedStatuses' => ['shipped']],
                    ];

                    foreach ($backfillPhases as $phase) {
                        if (! in_array($order->status, $phase['reachedStatuses'], true)) {
                            continue;
                        }
                        if (in_array($phase['action'], $actions, true)) {
                            continue;
                        }
                        if (! $dryRun) {
                            SalesOrderStatusHistory::create([
                                'salesorder_id' => $order->id,
                                'action_id'     => $phase['action_id'],
                                'action'        => $phase['action'],
                                'actor_email'   => 'system',
                                'actor_id'      => null,
                                'actor_name'    => 'System',
                                'metadata'      => [
                                    'backfill'    => true,
                                    'prev_values' => ['status' => $phase['from']],
                                    'new_values'  => ['status' => $phase['to']],
                                ],
                                'created_at'    => $timestamp,
                            ]);
                        }
                        $counts[$phase['action']]++;
                    }

                    if ($order->status === 'shipped' && ! in_array('COMPLETED', $actions, true) && $order->handed_to_warehouse_at !== null) {
                        if (! $dryRun) {
                            SalesOrderStatusHistory::create([
                                'salesorder_id' => $order->id,
                                'action_id'     => '912',
                                'action'        => 'COMPLETED',
                                'actor_email'   => 'system',
                                'actor_id'      => null,
                                'actor_name'    => 'System',
                                'metadata'      => [
                                    'backfill'    => true,
                                    'prev_values' => ['status' => 'shipped'],
                                    'new_values'  => ['status' => 'completed'],
                                ],
                                'created_at'    => $order->updated_at ?? $timestamp,
                            ]);
                        }
                        $counts['COMPLETED']++;
                    }

                    if ($order->is_canceled && ! in_array('CANCELLED', $actions, true)) {
                        if (! $dryRun) {
                            SalesOrderStatusHistory::create([
                                'salesorder_id' => $order->id,
                                'action_id'     => '000',
                                'action'        => 'CANCELLED',
                                'actor_email'   => 'system',
                                'actor_id'      => null,
                                'actor_name'    => 'System',
                                'metadata'      => [
                                    'backfill'    => true,
                                    'prev_values' => ['is_canceled' => false],
                                    'new_values'  => [
                                        'is_canceled'   => true,
                                        'cancel_reason' => $order->cancel_reason,
                                    ],
                                ],
                                'created_at'    => $order->updated_at ?? $order->created_at,
                            ]);
                        }
                        $counts['CANCELLED']++;
                    }
                }
            });

        $prefix = $dryRun ? '[dry-run] ' : '';
        foreach ($counts as $action => $count) {
            $this->info("{$prefix}{$action} dibuat: {$count}");
        }

        return self::SUCCESS;
    }
}
