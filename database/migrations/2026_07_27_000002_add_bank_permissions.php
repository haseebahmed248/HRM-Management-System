<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create Bank permissions and grant each to every role that already holds the
     * equivalent Department permission, so existing company/admin roles can manage
     * banks immediately without a reseed.
     */
    public function up(): void
    {
        // department permission => [bank permission, label]
        $map = [
            'manage-departments'        => ['manage-banks',        'Manage Banks'],
            'manage-any-departments'    => ['manage-any-banks',    'Manage Any Banks'],
            'manage-own-departments'    => ['manage-own-banks',    'Manage Own Banks'],
            'view-departments'          => ['view-banks',          'View Banks'],
            'create-departments'        => ['create-banks',        'Create Banks'],
            'edit-departments'          => ['edit-banks',          'Edit Banks'],
            'delete-departments'        => ['delete-banks',        'Delete Banks'],
            'toggle-status-departments' => ['toggle-status-banks', 'Toggle Status Banks'],
        ];

        $now = now();

        foreach ($map as $sourceName => [$newName, $label]) {
            $permId = DB::table('permissions')->where('name', $newName)->where('guard_name', 'web')->value('id');
            if (!$permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'module'      => 'banks',
                    'name'        => $newName,
                    'guard_name'  => 'web',
                    'label'       => $label,
                    'description' => null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }

            $sourceId = DB::table('permissions')->where('name', $sourceName)->where('guard_name', 'web')->value('id');
            if (!$sourceId) {
                continue;
            }

            $roleIds = DB::table('role_has_permissions')->where('permission_id', $sourceId)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                $already = DB::table('role_has_permissions')
                    ->where('permission_id', $permId)
                    ->where('role_id', $roleId)
                    ->exists();
                if (!$already) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permId,
                        'role_id'       => $roleId,
                    ]);
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = [
            'manage-banks', 'manage-any-banks', 'manage-own-banks',
            'view-banks', 'create-banks', 'edit-banks',
            'delete-banks', 'toggle-status-banks',
        ];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
