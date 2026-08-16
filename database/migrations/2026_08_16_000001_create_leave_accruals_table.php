<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger that records each leave accrual applied to an employee, so accrual
 * runs exactly once per (employee, leave type, period) even if a payroll run
 * is re-approved or re-processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('leave_type_id')->constrained('leave_types')->onDelete('cascade');
            $table->unsignedBigInteger('leave_balance_id')->nullable();
            $table->unsignedBigInteger('payroll_run_id')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1-12 for monthly accrual, 0 for a yearly accrual
            $table->decimal('days', 8, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // One accrual per employee, per leave type, per period — the idempotency guard.
            $table->unique(['employee_id', 'leave_type_id', 'year', 'month'], 'leave_accrual_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_accruals');
    }
};
