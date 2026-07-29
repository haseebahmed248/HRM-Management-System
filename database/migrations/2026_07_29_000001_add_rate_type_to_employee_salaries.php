<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 7 - multi-rate employment structures + rate-change history.
     *
     * rate_type: how basic_salary is interpreted. 'monthly' (default) keeps the
     * existing behaviour exactly, so every current employee is unaffected. The
     * other types treat basic_salary as a rate that is multiplied by the units
     * worked in the pay period (hours/days/weeks/fortnights).
     *
     * effective_from: when this salary/rate started. Used to keep a readable
     * history when a rate or rate type changes (old record is deactivated, a new
     * one is created), rather than overwriting.
     */
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_salaries', 'rate_type')) {
                $table->enum('rate_type', ['monthly', 'hourly', 'daily', 'weekly', 'fortnightly'])
                    ->default('monthly')
                    ->after('basic_salary');
            }
            if (!Schema::hasColumn('employee_salaries', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('rate_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            if (Schema::hasColumn('employee_salaries', 'rate_type')) {
                $table->dropColumn('rate_type');
            }
            if (Schema::hasColumn('employee_salaries', 'effective_from')) {
                $table->dropColumn('effective_from');
            }
        });
    }
};
