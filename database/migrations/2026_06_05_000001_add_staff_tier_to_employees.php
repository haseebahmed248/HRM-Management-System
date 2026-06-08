<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // track-a/11: senior / junior staff payroll separation.
        // Each employee is now classified as either 'senior' or 'junior'.
        // PayrollRunController / EmployeeController / ZambiaReportController
        // scope by this column against `manage-senior-payroll` and
        // `manage-junior-payroll` permissions, so a payroll officer can be
        // restricted to one tier at a time.
        if (! Schema::hasColumn('employees', 'staff_tier')) {
            DB::statement(
                "ALTER TABLE employees ADD COLUMN staff_tier ENUM('senior', 'junior') "
                . "NOT NULL DEFAULT 'junior'"
            );
        }

        // Backfill: existing rows already received the default 'junior'
        // because we added the column with a default. No further backfill
        // needed — the column is non-nullable so newly created rows are
        // safe too.
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'staff_tier')) {
            Schema::table('employees', function ($table) {
                $table->dropColumn('staff_tier');
            });
        }
    }
};
