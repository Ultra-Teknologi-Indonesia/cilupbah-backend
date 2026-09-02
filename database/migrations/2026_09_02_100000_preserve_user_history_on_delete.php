<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_histories', function (Blueprint $table): void {
            $table->uuid('actor_id_snapshot')->nullable()->after('actor_id');
            $table->string('actor_user_name', 150)->nullable()->after('actor_id_snapshot');
            $table->string('actor_user_email', 254)->nullable()->after('actor_user_name');
            $table->uuid('target_user_id_snapshot')->nullable()->after('target_user_id');
            $table->string('target_user_name', 150)->nullable()->after('target_user_id_snapshot');
            $table->string('target_user_email', 254)->nullable()->after('target_user_name');
            $table->index('target_user_id_snapshot', 'user_histories_target_snapshot_idx');
        });

        Schema::table('login_histories', function (Blueprint $table): void {
            $table->uuid('user_id_snapshot')->nullable()->after('user_id');
            $table->string('user_name', 150)->nullable()->after('user_id_snapshot');
            $table->string('user_email', 254)->nullable()->after('user_name');
            $table->index('user_id_snapshot', 'login_histories_user_snapshot_idx');
        });

        Schema::table('inbound_receipts', function (Blueprint $table): void {
            $table->string('received_by_name', 150)->nullable()->after('received_by_user_id');
            $table->string('received_by_email', 254)->nullable()->after('received_by_name');
        });

        Schema::table('inbound_participants', function (Blueprint $table): void {
            $table->uuid('user_id_snapshot')->nullable()->after('user_id');
            $table->string('user_name', 150)->nullable()->after('user_id_snapshot');
            $table->string('user_email', 254)->nullable()->after('user_name');
            $table->index('user_id_snapshot', 'inbound_participants_user_snapshot_idx');
        });

        Schema::table('inbound_assignments', function (Blueprint $table): void {
            $table->uuid('assigned_to_snapshot')->nullable()->after('assigned_to');
            $table->string('assigned_to_name', 150)->nullable()->after('assigned_to_snapshot');
            $table->string('assigned_to_email', 254)->nullable()->after('assigned_to_name');
            $table->uuid('assigned_by_snapshot')->nullable()->after('assigned_by');
            $table->string('assigned_by_name', 150)->nullable()->after('assigned_by_snapshot');
            $table->string('assigned_by_email', 254)->nullable()->after('assigned_by_name');
        });

        $this->backfillUserHistories();
        $this->backfillLoginHistories();
        $this->backfillInboundReceipts();
        $this->backfillInboundParticipants();
        $this->backfillInboundAssignments();

        Schema::table('user_histories', function (Blueprint $table): void {
            $table->dropForeign(['target_user_id']);
        });
        Schema::table('user_histories', function (Blueprint $table): void {
            $table->uuid('target_user_id')->nullable()->change();
            $table->foreign('target_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('login_histories', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('login_histories', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('inbound_receipts', function (Blueprint $table): void {
            $table->dropForeign(['received_by_user_id']);
        });
        Schema::table('inbound_receipts', function (Blueprint $table): void {
            $table->uuid('received_by_user_id')->nullable()->change();
            $table->foreign('received_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('inbound_participants', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('inbound_participants', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('inbound_assignments', function (Blueprint $table): void {
            $table->dropForeign(['assigned_to']);
            $table->dropForeign(['assigned_by']);
        });
        Schema::table('inbound_assignments', function (Blueprint $table): void {
            $table->uuid('assigned_to')->nullable()->change();
            $table->uuid('assigned_by')->nullable()->change();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_assignments', function (Blueprint $table): void {
            $table->dropForeign(['assigned_to']);
            $table->dropForeign(['assigned_by']);
            $table->dropColumn([
                'assigned_to_snapshot', 'assigned_to_name', 'assigned_to_email',
                'assigned_by_snapshot', 'assigned_by_name', 'assigned_by_email',
            ]);
        });
        Schema::table('inbound_assignments', function (Blueprint $table): void {
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('inbound_participants', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex('inbound_participants_user_snapshot_idx');
            $table->dropColumn(['user_id_snapshot', 'user_name', 'user_email']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('inbound_receipts', function (Blueprint $table): void {
            $table->dropForeign(['received_by_user_id']);
            $table->dropColumn(['received_by_name', 'received_by_email']);
            $table->foreign('received_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('login_histories', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex('login_histories_user_snapshot_idx');
            $table->dropColumn(['user_id_snapshot', 'user_name', 'user_email']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('user_histories', function (Blueprint $table): void {
            $table->dropForeign(['target_user_id']);
            $table->dropIndex('user_histories_target_snapshot_idx');
            $table->dropColumn([
                'actor_id_snapshot', 'actor_user_name', 'actor_user_email',
                'target_user_id_snapshot', 'target_user_name', 'target_user_email',
            ]);
            $table->foreign('target_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function backfillUserHistories(): void
    {
        DB::statement(<<<'SQL'
            UPDATE user_histories h
            SET actor_id_snapshot = h.actor_id,
                actor_user_name = actor.name,
                actor_user_email = actor.email,
                target_user_id_snapshot = h.target_user_id,
                target_user_name = target.name,
                target_user_email = target.email
            FROM users actor, users target
            WHERE h.actor_id = actor.id
              AND h.target_user_id = target.id
        SQL);

        DB::statement(<<<'SQL'
            UPDATE user_histories h
            SET target_user_id_snapshot = h.target_user_id,
                target_user_name = target.name,
                target_user_email = target.email
            FROM users target
            WHERE h.target_user_id = target.id
              AND h.target_user_id_snapshot IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE user_histories h
            SET actor_id_snapshot = h.actor_id,
                actor_user_name = actor.name,
                actor_user_email = actor.email
            FROM users actor
            WHERE h.actor_id = actor.id
              AND h.actor_id_snapshot IS NULL
        SQL);
    }

    private function backfillLoginHistories(): void
    {
        DB::statement(<<<'SQL'
            UPDATE login_histories h
            SET user_id_snapshot = h.user_id,
                user_name = u.name,
                user_email = u.email
            FROM users u
            WHERE h.user_id = u.id
        SQL);
    }

    private function backfillInboundReceipts(): void
    {
        DB::statement(<<<'SQL'
            UPDATE inbound_receipts r
            SET received_by_name = u.name,
                received_by_email = u.email
            FROM users u
            WHERE r.received_by_user_id = u.id
        SQL);
    }

    private function backfillInboundParticipants(): void
    {
        DB::statement(<<<'SQL'
            UPDATE inbound_participants p
            SET user_id_snapshot = p.user_id,
                user_name = u.name,
                user_email = u.email
            FROM users u
            WHERE p.user_id = u.id
        SQL);
    }

    private function backfillInboundAssignments(): void
    {
        DB::statement(<<<'SQL'
            UPDATE inbound_assignments a
            SET assigned_to_snapshot = a.assigned_to,
                assigned_to_name = assignee.name,
                assigned_to_email = assignee.email,
                assigned_by_snapshot = a.assigned_by,
                assigned_by_name = assigner.name,
                assigned_by_email = assigner.email
            FROM users assignee, users assigner
            WHERE a.assigned_to = assignee.id
              AND a.assigned_by = assigner.id
        SQL);

        DB::statement(<<<'SQL'
            UPDATE inbound_assignments a
            SET assigned_to_snapshot = a.assigned_to,
                assigned_to_name = assignee.name,
                assigned_to_email = assignee.email
            FROM users assignee
            WHERE a.assigned_to = assignee.id
              AND a.assigned_to_snapshot IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE inbound_assignments a
            SET assigned_by_snapshot = a.assigned_by,
                assigned_by_name = assigner.name,
                assigned_by_email = assigner.email
            FROM users assigner
            WHERE a.assigned_by = assigner.id
              AND a.assigned_by_snapshot IS NULL
        SQL);
    }
};
