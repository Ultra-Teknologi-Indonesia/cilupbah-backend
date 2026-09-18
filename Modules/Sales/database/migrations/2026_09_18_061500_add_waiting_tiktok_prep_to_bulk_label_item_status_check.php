<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'bulk_shipping_label_items_status_check';

    private const STATUSES = [
        'pending',
        'downloading',
        'waiting_awb',
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
            ->where('status', 'waiting_tiktok_prep')
            ->update([
                'status' => 'failed',
                'reason' => 'tiktok_label_prepare_removed',
                'updated_at' => now(),
            ]);

        $this->replaceConstraint(array_values(array_diff(self::STATUSES, ['waiting_tiktok_prep'])));
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
