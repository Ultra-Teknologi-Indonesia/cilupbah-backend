<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->char('assigned_to', 26)->nullable()->after('created_by');
            $table->char('assigned_by', 26)->nullable()->after('assigned_to');
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->timestamp('once_received_at')->nullable()->after('assigned_at');
            $table->timestamp('updated_version_at')->nullable()->after('once_received_at');
            $table->index('assigned_to');
            $table->index('once_received_at');
        });

        Schema::table('putaways', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->timestamp('updated_version_at')->nullable()->after('completed_at');
            $table->index('assigned_to');
        });

        Schema::table('picklists', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->timestamp('updated_version_at')->nullable()->after('completed_at');
            $table->index('picker_id');
        });

        // Backfill 1: sync active InboundAssignment → inbounds.assigned_to (denormalize).
        // Dokumen ongoing yang punya assignment aktif langsung dapat channel lock benar.
        DB::statement(<<<SQL
            UPDATE inbounds i
            SET assigned_to = ia.assigned_to,
                assigned_by = ia.assigned_by,
                assigned_at = ia.created_at
            FROM (
                SELECT DISTINCT ON (inbound_id) inbound_id, assigned_to, assigned_by, status, created_at
                FROM inbound_assignments
                WHERE status IN ('PENDING', 'IN_PROGRESS')
                ORDER BY inbound_id, created_at DESC
            ) ia
            WHERE i.id = ia.inbound_id AND i.assigned_to IS NULL
        SQL);

        // Backfill 2: once_received_at untuk dokumen sudah RECEIVED+ supaya guard web
        // langsung unlock (fix C1).
        DB::statement(<<<SQL
            UPDATE inbounds
            SET once_received_at = COALESCE(updated_at, created_at)
            WHERE status IN ('RECEIVED', 'PUTAWAY_IN_PROGRESS', 'COMPLETED')
              AND once_received_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['once_received_at']);
            $table->dropColumn(['assigned_to', 'assigned_by', 'assigned_at', 'once_received_at', 'updated_version_at']);
        });

        Schema::table('putaways', function (Blueprint $table) {
            $table->dropIndex(['assigned_to']);
            $table->dropColumn(['assigned_at', 'updated_version_at']);
        });

        Schema::table('picklists', function (Blueprint $table) {
            $table->dropIndex(['picker_id']);
            $table->dropColumn(['assigned_at', 'updated_version_at']);
        });
    }
};
