<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // e.g., "FY 2026", "2026-2027"
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->boolean('is_current')->default(false);   // Only one can be current per company
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index('created_by');
            $table->index(['created_by', 'is_current']);
            $table->index(['start_date', 'end_date']);
        });

        // Optional: Add financial_year_id to payroll_runs for linking
        Schema::table('payroll_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_runs', 'financial_year_id')) {
                $table->unsignedBigInteger('financial_year_id')->nullable()->after('status');
                $table->index('financial_year_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_runs', 'financial_year_id')) {
                $table->dropIndex(['financial_year_id']);
                $table->dropColumn('financial_year_id');
            }
        });

        Schema::dropIfExists('financial_years');
    }
};
