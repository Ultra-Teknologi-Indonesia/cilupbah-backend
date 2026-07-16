<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Inline backfill untuk row yang belum di-populate:
        //    a. received_by berupa UUID valid → langsung copy.
        //    b. received_by berupa nama → match users.name (case-insensitive, trim).
        DB::transaction(function () {
            $userIds = DB::table('users')->pluck('id')->all();
            $userIdSet = array_flip($userIds);

            $usersByName = DB::table('users')
                ->select('id', 'name')
                ->get()
                ->keyBy(fn ($u) => strtolower(trim((string) $u->name)))
                ->map(fn ($u) => $u->id);

            DB::table('inbound_receipts')
                ->whereNull('received_by_user_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($userIdSet, $usersByName) {
                    foreach ($rows as $row) {
                        $raw = (string) ($row->received_by ?? '');
                        $uid = null;

                        if (Str::isUuid($raw) && isset($userIdSet[$raw])) {
                            $uid = $raw;
                        } elseif ($raw !== '') {
                            $key = strtolower(trim($raw));
                            if ($usersByName->has($key)) {
                                $uid = $usersByName->get($key);
                            }
                        }

                        if ($uid) {
                            DB::table('inbound_receipts')
                                ->where('id', $row->id)
                                ->update(['received_by_user_id' => $uid]);
                        }
                    }
                });
        });

        // 2. Fail loud kalau masih ada NULL — jangan bikin data invalid dengan
        //    memaksa NOT NULL di kolom yang belum siap.
        $remaining = DB::table('inbound_receipts')->whereNull('received_by_user_id')->count();
        if ($remaining > 0) {
            throw new \RuntimeException(
                "Migration dibatalkan: masih ada {$remaining} baris inbound_receipts tanpa received_by_user_id. "
                . "Perbaiki manual (SELECT id, received_by FROM inbound_receipts WHERE received_by_user_id IS NULL) "
                . "lalu ulang migration."
            );
        }

        // 3. NOT NULL + drop kolom string legacy.
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->uuid('received_by_user_id')->nullable(false)->change();
            $table->dropColumn('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->string('received_by', 100)->nullable()->after('condition');
            $table->uuid('received_by_user_id')->nullable()->change();
        });

        // Repopulate received_by dari users.name untuk rollback graceful.
        DB::statement(<<<'SQL'
            UPDATE inbound_receipts r
            SET received_by = u.name
            FROM users u
            WHERE r.received_by_user_id = u.id
            SQL
        );
    }
};
