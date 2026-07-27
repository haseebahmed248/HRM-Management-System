<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class FinancialYearAudit extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'financial_year_id',
        'financial_year_name',
        'action',
        'description',
        'performed_by',
        'performed_by_name',
        'created_by',
    ];

    public function financialYear()
    {
        return $this->belongsTo(FinancialYear::class, 'financial_year_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
