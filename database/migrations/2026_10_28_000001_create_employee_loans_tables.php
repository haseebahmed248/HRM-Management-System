<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salary advances and staff loans recovered through payroll. The balance is
     * never stored: it is the loan amount minus its repayments, so unlocking and
     * reprocessing a run (which deletes its entries, and through the cascade its
     * payroll repayments) can never double-count a deduction.
     */
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->string('loan_type', 20)->default('advance'); // advance | loan
            $table->string('reference', 30);
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('installments');
            $table->decimal('installment_amount', 15, 2);
            $table->date('disbursed_on');
            $table->date('start_date'); // first payroll month the deduction applies to
            $table->string('status', 20)->default('active'); // active | paused | cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['created_by', 'status']);
            $table->unique(['created_by', 'reference']);
        });

        Schema::create('employee_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_loan_id')->constrained('employee_loans')->onDelete('cascade');
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->onDelete('cascade');
            $table->foreignId('payroll_entry_id')->nullable()->constrained('payroll_entries')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->string('method', 20)->default('payroll'); // payroll | manual
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['employee_loan_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loan_repayments');
        Schema::dropIfExists('employee_loans');
    }
};
