<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packlist_item_scan_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('packlist_id');
            $table->foreign('packlist_id')->references('id')->on('packlists')->cascadeOnDelete();
            $table->uuid('packlist_item_id');
            $table->foreign('packlist_item_id')->references('id')->on('packlist_items')->cascadeOnDelete();
            $table->uuid('scanned_by')->nullable();
            $table->unsignedInteger('qty_before');
            $table->unsignedInteger('qty_after');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['packlist_item_id', 'created_at']);
            $table->index(['packlist_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packlist_item_scan_events');
    }
};
