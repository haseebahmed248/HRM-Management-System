<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveAccrual extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'leave_balance_id',
        'payroll_run_id',
        'year',
        'month',
        'days',
        'created_by',
    ];

    protected $casts = [
        'year'  => 'integer',
        'month' => 'integer',
        'days'  => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
