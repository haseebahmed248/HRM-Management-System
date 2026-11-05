<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-grants the Loans & Advances permissions to every role that holds the
     * matching payroll-run permission.
     *
     * The 2026_10_28 migration did this once, but two gaps left HR and Manager
     * roles without access: companies created afterwards got default roles
     * without the loan permissions, and the Roles editor did not allow the
     * employee_loans module, so saving a role removed them again. Both are
     * fixed in code; this puts the permissions back on the affected roles.
     */
    public function up(): void
    {
        // payroll-run permission => loan permission
        $map = [
            'manage-payroll-runs'     => 'manage-employee-loans',
            'manage-any-payroll-runs' => 'manage-any-employee-loans',
            'manage-own-payroll-runs' => 'manage-own-employee-loans',
            'view-payroll-runs'       => 'view-employee-loans',
            'create-payroll-runs'     => 'create-employee-loans',
            'edit-payroll-runs'       => 'edit-employee-loans',
            'delete-payroll-runs'     => 'delete-employee-loans',
        ];

        foreach ($map as $sourceName => $loanName) {
            $sourceId = DB::table('permissions')->where('name', $sourceName)->where('guard_name', 'web')->value('id');
            $loanId = DB::table('permissions')->where('name', $loanName)->where('guard_name', 'web')->value('id');
            if (!$sourceId || !$loanId) {
                continue;
            }

            $missing = DB::table('role_has_permissions as src')
                ->where('src.permission_id', $sourceId)
                ->whereNotExists(function ($q) use ($loanId) {
                    $q->from('role_has_permissions as dst')
                        ->whereColumn('dst.role_id', 'src.role_id')
                        ->where('dst.permission_id', $loanId);
                })
                ->pluck('src.role_id');

            foreach ($missing as $roleId) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $loanId,
                    'role_id'       => $roleId,
                ]);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing to undo: the permissions belong to the 2026_10_28 migration.
    }
};
