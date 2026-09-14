<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table): void {

            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasOversizedDescription = DB::table('sales_order_items')
            ->whereRaw('description IS NOT NULL AND char_length(description) > 255')
            ->exists();

        if ($hasOversizedDescription) {
            throw new RuntimeException(
                'Cannot restore sales_order_items.description to VARCHAR(255): oversized descriptions exist.',
            );
        }

        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->string('description')->nullable()->change();
        });
    }
};
