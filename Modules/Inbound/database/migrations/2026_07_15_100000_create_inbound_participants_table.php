<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inbound_id')->constrained('inbounds')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('role', 20)->default('RECEIVER');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->uuid('withdrawn_by')->nullable();
            $table->foreign('withdrawn_by')->references('id')->on('users')->onDelete('set null');
            $table->text('withdraw_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['inbound_id', 'user_id']);
            $table->index(['inbound_id', 'status']);
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->timestamp('receiving_started_at')->nullable()->after('once_received_at');
        });

        // Backfill: inbound aktif dengan assigned_to → 1 participant ACTIVE.
        DB::table('inbounds')
            ->whereNotNull('assigned_to')
            ->whereIn('status', ['DRAFT', 'PARTIAL'])
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                $rowsToInsert = [];
                foreach ($rows as $inbound) {
                    $rowsToInsert[] = [
                        'id' => (string) \Illuminate\Support\Str::uuid7(),
                        'inbound_id' => $inbound->id,
                        'user_id' => $inbound->assigned_to,
                        'role' => 'RECEIVER',
                        'joined_at' => $inbound->assigned_at ?? $inbound->updated_at ?? now(),
                        'completed_at' => null,
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (! empty($rowsToInsert)) {
                    DB::table('inbound_participants')->insert($rowsToInsert);
                }
            });

        // Backfill: inbound sudah RECEIVED+ dengan assigned_to → 1 participant DONE.
        DB::table('inbounds')
            ->whereNotNull('assigned_to')
            ->whereIn('status', ['RECEIVED', 'PUTAWAY_IN_PROGRESS', 'COMPLETED'])
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                $rowsToInsert = [];
                foreach ($rows as $inbound) {
                    $completedAt = $inbound->once_received_at ?? $inbound->updated_at ?? now();
                    $rowsToInsert[] = [
                        'id' => (string) \Illuminate\Support\Str::uuid7(),
                        'inbound_id' => $inbound->id,
                        'user_id' => $inbound->assigned_to,
                        'role' => 'RECEIVER',
                        'joined_at' => $inbound->assigned_at ?? $inbound->updated_at ?? now(),
                        'completed_at' => $completedAt,
                        'status' => 'DONE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (! empty($rowsToInsert)) {
                    DB::table('inbound_participants')->insert($rowsToInsert);
                }
            });

        // Backfill receiving_started_at dari joined_at earliest per inbound (kalau ada participant).
        DB::statement("
            UPDATE inbounds
            SET receiving_started_at = sub.first_join
            FROM (
                SELECT inbound_id, MIN(joined_at) AS first_join
                FROM inbound_participants
                GROUP BY inbound_id
            ) AS sub
            WHERE inbounds.id = sub.inbound_id
              AND inbounds.receiving_started_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropColumn('receiving_started_at');
        });
        Schema::dropIfExists('inbound_participants');
    }
};
