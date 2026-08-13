<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    private const STATUSES = [
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
        $list = collect(self::STATUSES)->map(fn ($s) => "'{$s}'")->implode(', ');

        DB::statement('ALTER TABLE bulk_shipping_label_items DROP CONSTRAINT IF EXISTS bulk_shipping_label_items_status_check');
        DB::statement(
            "ALTER TABLE bulk_shipping_label_items
             ADD CONSTRAINT bulk_shipping_label_items_status_check
             CHECK (status IN ({$list}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bulk_shipping_label_items DROP CONSTRAINT IF EXISTS bulk_shipping_label_items_status_check');
        DB::statement(
            "ALTER TABLE bulk_shipping_label_items
             ADD CONSTRAINT bulk_shipping_label_items_status_check
             CHECK (status IN ('pending', 'downloading', 'waiting_shopee_prep', 'done', 'failed'))"
        );
    }
};
