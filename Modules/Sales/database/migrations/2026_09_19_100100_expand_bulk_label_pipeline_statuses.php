<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'bulk_shipping_label_items_status_check';

    private const STATUSES = [
        'pending',
        'downloading',
        'transforming',
        'ready',
        'waiting_awb',
        'waiting_marketplace',
        'waiting_shopee_prep',
        'waiting_tiktok_prep',
        'waiting_lazada_prep',
        'done',
        'failed',
        'skipped_instant',
    ];

    public function up(): void
    {
        $this->replaceConstraint(self::STATUSES);
    }

    public function down(): void
    {
        DB::table('bulk_shipping_label_items')
            ->whereIn('status', ['transforming', 'ready', 'waiting_marketplace'])
            ->update([
                'status' => 'failed',
                'reason' => 'pipeline_status_removed',
                'updated_at' => now(),
            ]);

        $this->replaceConstraint([
            'pending',
            'downloading',
            'waiting_awb',
            'waiting_shopee_prep',
            'waiting_tiktok_prep',
            'waiting_lazada_prep',
            'done',
            'failed',
            'skipped_instant',
        ]);
    }

    private function replaceConstraint(array $statuses): void
    {
        $list = implode(', ', array_map(static fn (string $status): string => "'{$status}'", $statuses));

        DB::statement('ALTER TABLE bulk_shipping_label_items DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE bulk_shipping_label_items ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (status IN ({$list}))"
        );
    }
};
