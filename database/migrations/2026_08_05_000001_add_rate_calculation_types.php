<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds rate-based calculation types so a component can be valued from the
 * employee's hourly rate, a percentage of the hourly rate, or the daily wage.
 *
 *  - hourly               : amount = hourly_rate * default_amount (default_amount = number of hours)
 *  - daily                : amount = daily_rate  * default_amount (default_amount = number of days)
 *  - percentage_of_hourly : amount = (percentage_of_basic / 100) * hourly_rate
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE salary_components MODIFY COLUMN calculation_type "
            . "ENUM('fixed','percentage','zambia_paye','zambia_pension','hourly','daily','percentage_of_hourly') "
            . "NOT NULL DEFAULT 'fixed'"
        );
    }

    public function down(): void
    {
        // Revert any rows using the new types back to 'fixed' before shrinking the enum.
        DB::table('salary_components')
            ->whereIn('calculation_type', ['hourly', 'daily', 'percentage_of_hourly'])
            ->update(['calculation_type' => 'fixed']);

        DB::statement(
            "ALTER TABLE salary_components MODIFY COLUMN calculation_type "
            . "ENUM('fixed','percentage','zambia_paye','zambia_pension') "
            . "NOT NULL DEFAULT 'fixed'"
        );
    }
};
