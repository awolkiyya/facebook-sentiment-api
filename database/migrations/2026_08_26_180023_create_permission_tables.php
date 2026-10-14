<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teams = config('permission.teams');

        $tableNames = config('permission.table_names');

        $columnNames = config('permission.column_names');

        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';

        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        $modelMorphKey = $columnNames['model_morph_key'] ?? 'model_id';

        throw_if(
            empty($tableNames),
            Exception::class,
            'Error: config/permission.php is not loaded. Run "php artisan config:clear" and try again.'
        );

        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        |
        | UUID primary key.
        |
        */

        Schema::create(
            $tableNames['permissions'],
            function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->string('name');

                $table->string('guard_name');

                $table->timestamps();

                $table->unique(
                    ['name', 'guard_name'],
                    'permissions_name_guard_name_unique'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        |
        | UUID primary key.
        |
        */

        Schema::create(
            $tableNames['roles'],
            function (Blueprint $table) use (
                $teams,
                $columnNames
            ) {
                $table->uuid('id')->primary();

                /*
                |--------------------------------------------------------------------------
                | Teams
                |--------------------------------------------------------------------------
                */

                if ($teams) {
                    $teamForeignKey = $columnNames['team_foreign_key'];

                    $table->uuid($teamForeignKey)->nullable();

                    $table->index(
                        $teamForeignKey,
                        'roles_team_foreign_key_index'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Role
                |--------------------------------------------------------------------------
                */

                $table->string('name');

                $table->string('guard_name');

                /*
                |--------------------------------------------------------------------------
                | System Role
                |--------------------------------------------------------------------------
                |
                | true  = protected/system-managed role
                | false = user-managed/custom role
                |
                */

                $table->boolean('is_system')->default(false);

                $table->timestamps();

                /*
                |--------------------------------------------------------------------------
                | Unique Role
                |--------------------------------------------------------------------------
                */

                if ($teams) {
                    $table->unique(
                        [
                            $columnNames['team_foreign_key'],
                            'name',
                            'guard_name',
                        ],
                        'roles_team_name_guard_name_unique'
                    );
                } else {
                    $table->unique(
                        [
                            'name',
                            'guard_name',
                        ],
                        'roles_name_guard_name_unique'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Model Has Permissions
        |--------------------------------------------------------------------------
        |
        | Polymorphic relation:
        |
        | User UUID
        |     ↓
        | model_has_permissions
        |
        */

        Schema::create(
            $tableNames['model_has_permissions'],
            function (Blueprint $table) use (
                $tableNames,
                $columnNames,
                $pivotPermission,
                $teams,
                $modelMorphKey
            ) {
                /*
                |--------------------------------------------------------------------------
                | Permission UUID
                |--------------------------------------------------------------------------
                */

                $table->uuid($pivotPermission);

                /*
                |--------------------------------------------------------------------------
                | Polymorphic Model
                |--------------------------------------------------------------------------
                */

                $table->string('model_type');

                /*
                |--------------------------------------------------------------------------
                | UUID Model ID
                |--------------------------------------------------------------------------
                */

                $table->uuid($modelMorphKey);

                /*
                |--------------------------------------------------------------------------
                | Index
                |--------------------------------------------------------------------------
                */

                $table->index(
                    [$modelMorphKey, 'model_type'],
                    'model_has_permissions_model_type_index'
                );

                /*
                |--------------------------------------------------------------------------
                | Permission Foreign Key
                |--------------------------------------------------------------------------
                */

                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->cascadeOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Teams
                |--------------------------------------------------------------------------
                */

                if ($teams) {
                    $teamForeignKey = $columnNames['team_foreign_key'];

                    $table->uuid($teamForeignKey);

                    $table->index(
                        $teamForeignKey,
                        'model_has_permissions_team_foreign_key_index'
                    );

                    $table->primary(
                        [
                            $teamForeignKey,
                            $pivotPermission,
                            $modelMorphKey,
                            'model_type',
                        ],
                        'model_has_permissions_team_permission_model_primary'
                    );
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Primary Key
                    |--------------------------------------------------------------------------
                    */

                    $table->primary(
                        [
                            $pivotPermission,
                            $modelMorphKey,
                            'model_type',
                        ],
                        'model_has_permissions_permission_model_primary'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Model Has Roles
        |--------------------------------------------------------------------------
        |
        | Polymorphic relation:
        |
        | User UUID
        |     ↓
        | model_has_roles
        |
        */

        Schema::create(
            $tableNames['model_has_roles'],
            function (Blueprint $table) use (
                $tableNames,
                $columnNames,
                $pivotRole,
                $teams,
                $modelMorphKey
            ) {
                /*
                |--------------------------------------------------------------------------
                | Role UUID
                |--------------------------------------------------------------------------
                */

                $table->uuid($pivotRole);

                /*
                |--------------------------------------------------------------------------
                | Polymorphic Model
                |--------------------------------------------------------------------------
                */

                $table->string('model_type');

                /*
                |--------------------------------------------------------------------------
                | UUID Model ID
                |--------------------------------------------------------------------------
                */

                $table->uuid($modelMorphKey);

                /*
                |--------------------------------------------------------------------------
                | Index
                |--------------------------------------------------------------------------
                */

                $table->index(
                    [$modelMorphKey, 'model_type'],
                    'model_has_roles_model_type_index'
                );

                /*
                |--------------------------------------------------------------------------
                | Role Foreign Key
                |--------------------------------------------------------------------------
                */

                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->cascadeOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Teams
                |--------------------------------------------------------------------------
                */

                if ($teams) {
                    $teamForeignKey = $columnNames['team_foreign_key'];

                    $table->uuid($teamForeignKey);

                    $table->index(
                        $teamForeignKey,
                        'model_has_roles_team_foreign_key_index'
                    );

                    $table->primary(
                        [
                            $teamForeignKey,
                            $pivotRole,
                            $modelMorphKey,
                            'model_type',
                        ],
                        'model_has_roles_team_role_model_primary'
                    );
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Primary Key
                    |--------------------------------------------------------------------------
                    */

                    $table->primary(
                        [
                            $pivotRole,
                            $modelMorphKey,
                            'model_type',
                        ],
                        'model_has_roles_role_model_primary'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Role Has Permissions
        |--------------------------------------------------------------------------
        |
        | roles
        |   ↓
        | role_has_permissions
        |   ↓
        | permissions
        |
        */

        Schema::create(
            $tableNames['role_has_permissions'],
            function (Blueprint $table) use (
                $tableNames,
                $pivotRole,
                $pivotPermission
            ) {
                /*
                |--------------------------------------------------------------------------
                | Permission UUID
                |--------------------------------------------------------------------------
                */

                $table->uuid($pivotPermission);

                /*
                |--------------------------------------------------------------------------
                | Role UUID
                |--------------------------------------------------------------------------
                */

                $table->uuid($pivotRole);

                /*
                |--------------------------------------------------------------------------
                | Permission Foreign Key
                |--------------------------------------------------------------------------
                */

                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->cascadeOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Role Foreign Key
                |--------------------------------------------------------------------------
                */

                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->cascadeOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Composite Primary Key
                |--------------------------------------------------------------------------
                */

                $table->primary(
                    [
                        $pivotPermission,
                        $pivotRole,
                    ],
                    'role_has_permissions_permission_role_primary'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Clear Permission Cache
        |--------------------------------------------------------------------------
        */

        app('cache')
            ->store(
                config('permission.cache.store') !== 'default'
                    ? config('permission.cache.store')
                    : null
            )
            ->forget(
                config('permission.cache.key')
            );
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        /*
        |--------------------------------------------------------------------------
        | Drop Pivot Tables First
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists(
            $tableNames['role_has_permissions']
        );

        Schema::dropIfExists(
            $tableNames['model_has_roles']
        );

        Schema::dropIfExists(
            $tableNames['model_has_permissions']
        );

        /*
        |--------------------------------------------------------------------------
        | Drop Main Tables
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists(
            $tableNames['roles']
        );

        Schema::dropIfExists(
            $tableNames['permissions']
        );
    }
};