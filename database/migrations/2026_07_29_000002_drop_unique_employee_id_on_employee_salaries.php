<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 7c - keep payroll/rate history. employee_salaries had a UNIQUE index on
     * employee_id (one salary row per employee ever), which prevented keeping old
     * rate records when a rate/rate type changes. We drop it so an employee can
     * have one ACTIVE salary plus any number of retired (is_active = false)
     * history rows. Only-one-active is enforced in application code (the update
     * flow retires the old row before creating the new active one, and store()
     * only blocks when an active row already exists). A plain index is kept for
     * lookups.
     */
    public function up(): void
    {
        // The unique index backs a foreign key, so first add a plain index for the
        // FK to lean on, then the unique can be dropped.
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->index('employee_id', 'employee_salaries_employee_id_index');
        });
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropUnique('employee_salaries_employee_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->unique('employee_id', 'employee_salaries_employee_id_unique');
        });
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropIndex('employee_salaries_employee_id_index');
        });
    }
};
