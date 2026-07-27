<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Financial Period Management (financial years) is gated by the
     * 'manage-payroll-settings' permission, but that permission was only ever
     * held by superadmin, so company accounts couldn't open the fully-built
     * Financial Years screen. Grant it to every role that already holds
     * 'manage-departments' (i.e. company-admin roles), matching how banks and
     * staff-tiers were rolled out. Tax settings remain superadmin-only — they
     * are protected by a separate `type === 'superadmin'` check, not this
     * permission — so this does not expose tax settings to companies.
     */
    public function up(): void
    {
        $now = now();

        // Ensure the permission exists (it normally does, from PermissionSeeder).
        $permId = DB::table('permissions')->where('name', 'manage-payroll-settings')->where('guard_name', 'web')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId([
                'module'      => 'payroll_settings',
                'name'        => 'manage-payroll-settings',
                'guard_name'  => 'web',
                'label'       => 'Manage Payroll Settings',
                'description' => 'Can manage payroll settings like financial years',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $sourceId = DB::table('permissions')->where('name', 'manage-departments')->where('guard_name', 'web')->value('id');
        if (!$sourceId) {
            return;
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

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Revoke from the shared 'company' role only; leave superadmin intact.
        $permId = DB::table('permissions')->where('name', 'manage-payroll-settings')->where('guard_name', 'web')->value('id');
        $companyRoleId = DB::table('roles')->where('name', 'company')->where('guard_name', 'web')->value('id');
        if ($permId && $companyRoleId) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permId)
                ->where('role_id', $companyRoleId)
                ->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
