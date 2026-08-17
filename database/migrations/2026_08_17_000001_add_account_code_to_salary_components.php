<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL account code per salary component, so it can be posted on the
 * Payroll Summary Journal report under the company's chart of accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->string('account_code', 50)->nullable()->after('percentage_of_basic');
        });
    }

    public function down(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn('account_code');
        });
    }
};
