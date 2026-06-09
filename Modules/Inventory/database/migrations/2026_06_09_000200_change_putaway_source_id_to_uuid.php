<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * putaways.source_id bersifat polimorfik (mis. source_type = INBOUND -> inbound id),
     * yang seluruhnya UUID. Sebelumnya varchar(32) sehingga tidak muat UUID kanonik 36-char.
     * Ubah menjadi uuid. PostgreSQL menerima cast dari hex 32-char maupun kanonik 36-char.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE putaways ALTER COLUMN source_id TYPE uuid USING source_id::uuid');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE putaways ALTER COLUMN source_id TYPE varchar(32) USING source_id::text');
    }
};
