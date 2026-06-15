<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Support\AccountMappingKey;

return new class extends Migration
{

    public function up(): void
    {
        $exists = DB::table('account_mappings')
            ->where('key', AccountMappingKey::COGS)
            ->exists();

        if ($exists) {
            return;
        }

        $accountId = DB::table('accounts')
            ->where('account_code', AccountMappingKey::defaultCode(AccountMappingKey::COGS))
            ->value('id');

        DB::table('account_mappings')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'key' => AccountMappingKey::COGS,
            'account_id' => $accountId, 
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('account_mappings')->where('key', AccountMappingKey::COGS)->delete();
    }
};
