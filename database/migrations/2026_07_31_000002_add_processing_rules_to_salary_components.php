<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->boolean('affect_notional_pay')->default(false)->after('is_mandatory');
            $table->boolean('affect_payslip')->default(true)->after('affect_notional_pay');
            $table->boolean('print_on_payslip')->default(true)->after('affect_payslip');
            $table->boolean('pro_rata_start_end')->default(false)->after('print_on_payslip');
            $table->boolean('compulsory_deduction')->default(false)->after('pro_rata_start_end');
            $table->enum('delay_type', ['none', 'delay_for', 'use_for_next'])->default('none')->after('compulsory_deduction');
            $table->unsignedInteger('delay_months')->nullable()->after('delay_type');
            $table->enum('clear_totals', ['year_end', 'never', 'specific_month', 'end_of_cycle'])->default('year_end')->after('delay_months');
            $table->unsignedTinyInteger('clear_specific_month')->nullable()->after('clear_totals');
            $table->date('cycle_start_date')->nullable()->after('clear_specific_month');
            $table->unsignedInteger('cycle_length_months')->nullable()->after('cycle_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn([
                'affect_notional_pay',
                'affect_payslip',
                'print_on_payslip',
                'pro_rata_start_end',
                'compulsory_deduction',
                'delay_type',
                'delay_months',
                'clear_totals',
                'clear_specific_month',
                'cycle_start_date',
                'cycle_length_months',
            ]);
        });
    }
};
