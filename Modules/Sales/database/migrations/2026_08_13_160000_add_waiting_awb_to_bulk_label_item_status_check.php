<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'bulk_shipping_label_items_status_check';

    private const STATUSES_WITH_WAITING_AWB = [
        'pending',
        'downloading',
        'waiting_awb',
        'waiting_shopee_prep',
        'waiting_lazada_prep',
        'done',
        'failed',
        'skipped_instant',
    ];

    private const STATUSES_WITHOUT_WAITING_AWB = [
        'pending',
        'downloading',
        'waiting_shopee_prep',
        'waiting_lazada_prep',
        'done',
        'failed',
        'skipped_instant',
    ];

    public function up(): void
    {
        $this->replaceConstraint(self::STATUSES_WITH_WAITING_AWB);
    }

    public function down(): void
    {
        DB::table('bulk_shipping_label_items')
            ->where('status', 'waiting_awb')
            ->update(['status' => 'failed', 'reason' => 'no_awb']);

        $this->replaceConstraint(self::STATUSES_WITHOUT_WAITING_AWB);
    }

    private function replaceConstraint(array $statuses): void
    {
        $list = implode(', ', array_map(fn ($s) => "'{$s}'", $statuses));

        DB::statement('ALTER TABLE bulk_shipping_label_items DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE bulk_shipping_label_items ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (status IN ({$list}))"
        );
    }
};
