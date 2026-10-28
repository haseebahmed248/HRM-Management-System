<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLoanRepayment extends Model
{
    protected $fillable = [
        'employee_loan_id',
        'payroll_run_id',
        'payroll_entry_id',
        'amount',
        'paid_on',
        'method',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
