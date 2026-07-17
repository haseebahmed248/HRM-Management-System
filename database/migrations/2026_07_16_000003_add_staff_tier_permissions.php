<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the Staff Tier permissions and grant each to every role that already
     * holds the equivalent Designation permission — so existing company/admin
     * roles can manage staff tiers immediately without a reseed.
     */
    public function up(): void
    {
        // designation permission => [staff-tier permission, label]
        $map = [
            'manage-designations'          => ['manage-staff-tiers',          'Manage Staff Tiers'],
            'manage-any-designations'      => ['manage-any-staff-tiers',      'Manage Any Staff Tiers'],
            'manage-own-designations'      => ['manage-own-staff-tiers',      'Manage Own Staff Tiers'],
            'view-designations'            => ['view-staff-tiers',            'View Staff Tiers'],
            'create-designations'          => ['create-staff-tiers',          'Create Staff Tiers'],
            'edit-designations'            => ['edit-staff-tiers',            'Edit Staff Tiers'],
            'delete-designations'          => ['delete-staff-tiers',          'Delete Staff Tiers'],
            'toggle-status-designations'   => ['toggle-status-staff-tiers',   'Toggle Status Staff Tiers'],
        ];

        $now = now();

        foreach ($map as $sourceName => [$newName, $label]) {
            // Create the staff-tier permission if missing.
            $permId = DB::table('permissions')->where('name', $newName)->where('guard_name', 'web')->value('id');
            if (!$permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'module'      => 'staff_tiers',
                    'name'        => $newName,
                    'guard_name'  => 'web',
                    'label'       => $label,
                    'description' => null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }

            // Mirror role grants from the matching designation permission.
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

        // Clear cached Spatie permissions so the new grants take effect immediately.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = [
            'manage-staff-tiers', 'manage-any-staff-tiers', 'manage-own-staff-tiers',
            'view-staff-tiers', 'create-staff-tiers', 'edit-staff-tiers',
            'delete-staff-tiers', 'toggle-status-staff-tiers',
        ];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
