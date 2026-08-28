<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct vs indirect labour classification per employee.
 *
 * Cost accounting distinguishes labour that can be traced to production
 * (direct) from labour that cannot (indirect). On the Payroll Summary Journal
 * this changes presentation only: direct-labour staff are posted as a single
 * "Direct Labour" figure covering basic, allowances and employer statutory
 * contributions, while indirect-labour staff keep the itemised component
 * lines. Net pay, statutory calculations and the credit side are untouched.
 *
 * Defaults to 'indirect' so existing payrolls keep reporting exactly as they
 * do today until someone explicitly classifies an employee as direct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('labour_category', 10)->default('indirect')->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('labour_category');
        });
    }
};
