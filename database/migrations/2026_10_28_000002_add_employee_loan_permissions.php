<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Loan permissions, granted to every role that already holds the matching
     * payroll-run permission, so payroll staff can use the module straight away.
     */
    public function up(): void
    {
        // payroll-run permission => [loan permission, label]
        $map = [
            'manage-payroll-runs'     => ['manage-employee-loans',     'Manage Loans & Advances'],
            'manage-any-payroll-runs' => ['manage-any-employee-loans', 'Manage All Loans & Advances'],
            'manage-own-payroll-runs' => ['manage-own-employee-loans', 'Manage Own Loans & Advances'],
            'view-payroll-runs'       => ['view-employee-loans',       'View Loans & Advances'],
            'create-payroll-runs'     => ['create-employee-loans',     'Create Loans & Advances'],
            'edit-payroll-runs'       => ['edit-employee-loans',       'Edit Loans & Advances'],
            'delete-payroll-runs'     => ['delete-employee-loans',     'Delete Loans & Advances'],
        ];

        $now = now();

        foreach ($map as $sourceName => [$newName, $label]) {
            $permId = DB::table('permissions')->where('name', $newName)->where('guard_name', 'web')->value('id');
            if (!$permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'module'      => 'employee_loans',
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
            'manage-employee-loans', 'manage-any-employee-loans', 'manage-own-employee-loans',
            'view-employee-loans', 'create-employee-loans', 'edit-employee-loans', 'delete-employee-loans',
        ];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
