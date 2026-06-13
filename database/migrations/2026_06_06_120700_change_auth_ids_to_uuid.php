<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        Schema::table($tableNames['role_has_permissions'], function (Blueprint $table) use ($pivotRole, $pivotPermission, $tableNames) {
            $table->dropForeign([$pivotPermission]);
            $table->dropForeign([$pivotRole]);
            $table->dropPrimary('role_has_permissions_permission_id_role_id_primary');
        });

        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($pivotPermission, $tableNames) {
            $table->dropForeign([$pivotPermission]);

            $table->dropPrimary('model_has_permissions_permission_model_type_primary');
        });

        Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($pivotRole, $tableNames) {
            $table->dropForeign([$pivotRole]);
            $table->dropPrimary('model_has_roles_role_model_type_primary');
        });

        if (DB::getDriverName() !== 'sqlite') {
            if (Schema::hasTable('putaways')) {
                Schema::table('putaways', function (Blueprint $table) {
                    $table->dropForeign(['assigned_to']);
                });
            }
            if (Schema::hasTable('inbound_assignments')) {
                Schema::table('inbound_assignments', function (Blueprint $table) {
                    $table->dropForeign(['assigned_to']);
                    $table->dropForeign(['assigned_by']);
                });
            }
        }

        $tables = [
            'users' => ['id'],
            'sessions' => ['user_id'],
            $tableNames['roles'] => ['id'],
            $tableNames['permissions'] => ['id'],
            $tableNames['role_has_permissions'] => [$pivotPermission, $pivotRole],
            $tableNames['model_has_permissions'] => [$pivotPermission, $columnNames['model_morph_key']],
            $tableNames['model_has_roles'] => [$pivotRole, $columnNames['model_morph_key']],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::getDriverName() !== 'sqlite') {

                    if ($column === 'id') {
                        DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
                    }
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE UUID USING LPAD({$column}::text, 32, '0')::uuid");
                }
            }
        }

        if (DB::getDriverName() !== 'sqlite') {
            if (Schema::hasTable('putaways')) {
                DB::statement("ALTER TABLE putaways ALTER COLUMN assigned_to TYPE UUID USING LPAD(assigned_to::text, 32, '0')::uuid");
                Schema::table('putaways', function (Blueprint $table) {
                    $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
                });
            }
            if (Schema::hasTable('inbound_assignments')) {
                DB::statement("ALTER TABLE inbound_assignments ALTER COLUMN assigned_to TYPE UUID USING LPAD(assigned_to::text, 32, '0')::uuid");
                DB::statement("ALTER TABLE inbound_assignments ALTER COLUMN assigned_by TYPE UUID USING LPAD(assigned_by::text, 32, '0')::uuid");
                Schema::table('inbound_assignments', function (Blueprint $table) {
                    $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
                });
            }
        }

        Schema::table($tableNames['role_has_permissions'], function (Blueprint $table) use ($pivotRole, $pivotPermission, $tableNames) {
            $table->foreign($pivotPermission)->references('id')->on($tableNames['permissions'])->cascadeOnDelete();
            $table->foreign($pivotRole)->references('id')->on($tableNames['roles'])->cascadeOnDelete();
            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($pivotPermission, $columnNames, $tableNames) {
            $table->foreign($pivotPermission)->references('id')->on($tableNames['permissions'])->cascadeOnDelete();
            $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($pivotRole, $columnNames, $tableNames) {
            $table->foreign($pivotRole)->references('id')->on($tableNames['roles'])->cascadeOnDelete();
            $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'], 'model_has_roles_role_model_type_primary');
        });
    }

    public function down(): void
    {

    }
};
