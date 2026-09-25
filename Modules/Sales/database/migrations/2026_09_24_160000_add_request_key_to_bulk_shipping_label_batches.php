<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('bulk_shipping_label_batches', 'request_key')) {
            Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
                $table->string('request_key', 64)->nullable();
            });
        }
        ConcurrentIndex::create('bulk_label_active_request_idx', 'bulk_shipping_label_batches', ['user_id', 'request_key', 'status'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS bulk_label_active_request_idx ON bulk_shipping_label_batches (user_id, request_key, status)');
        if (! DB::selectOne("SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'bulk_label_active_request_idx' AND i.indisvalid")) {
            throw new RuntimeException('Bulk label request index belum siap; ulangi migrasi sebelum rollout.');
        }
    }

    public function down(): void
    {
        ConcurrentIndex::drop('bulk_label_active_request_idx');
        Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
            $table->dropColumn('request_key');
        });
    }
};
