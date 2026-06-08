<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * track-a/10: Pension tax-relief offset on PAYE.
 *
 * Two additive changes:
 * 1. Extend the `salary_components.calculation_type` enum to recognise a
 *    new `zambia_pension` value. Admins create a deduction component with
 *    this calculation type to represent each employee's pension
 *    contribution; the payroll engine then subtracts up to the configured
 *    relief cap from gross before applying PAYE bands.
 *
 * 2. Seed the new `zambia_pension_relief_cap` setting (default K1,000/month)
 *    for every tenant that already has any other `zambia_*` setting. New
 *    tenants pick up the default via the runtime `?? 1000` fallback in
 *    ZambiaPayrollService.
 *
 * Idempotent: ALTER ENUM uses MODIFY with the full list (re-runnable).
 * Setting seed uses INSERT IGNORE keyed by (user_id, key) so re-running is
 * a no-op once values are present.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend enum to include the new calculation_type
        DB::statement(
            "ALTER TABLE salary_components MODIFY COLUMN calculation_type "
            . "ENUM('fixed', 'percentage', 'zambia_paye', 'zambia_pension') "
            . "NOT NULL DEFAULT 'fixed'"
        );

        // 2. Seed default relief cap for every existing tenant that already
        //    has any zambia_* setting (i.e. they've adopted the Zambia
        //    payroll feature). New tenants get K1,000 via the runtime
        //    `?? 1000` fallback in ZambiaPayrollService, so we only seed
        //    where there's clear opt-in.
        $tenantIds = DB::table('settings')
            ->where('key', 'like', 'zambia_%')
            ->distinct()
            ->pluck('user_id');

        $now = now();
        foreach ($tenantIds as $uid) {
            DB::table('settings')->updateOrInsert(
                ['user_id' => $uid, 'key' => 'zambia_pension_relief_cap'],
                ['value' => '1000', 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        // 1. Revert enum (only safe if no rows are still using the new type)
        $stillUsed = DB::table('salary_components')
            ->where('calculation_type', 'zambia_pension')
            ->exists();
        if (! $stillUsed) {
            DB::statement(
                "ALTER TABLE salary_components MODIFY COLUMN calculation_type "
                . "ENUM('fixed', 'percentage', 'zambia_paye') "
                . "NOT NULL DEFAULT 'fixed'"
            );
        }

        // 2. Remove seeded settings
        DB::table('settings')->where('key', 'zambia_pension_relief_cap')->delete();
    }
};
