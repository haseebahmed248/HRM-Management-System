<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class FinancialYear extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'is_current',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'status' => 'string',
    ];

    /**
     * Get the creator of this financial year.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get payroll runs in this financial year.
     */
    public function payrollRuns()
    {
        return $this->hasMany(PayrollRun::class, 'financial_year_id');
    }

    /**
     * Scope to get only active financial years.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get the current financial year.
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Check if a date falls within this financial year.
     */
    public function containsDate($date): bool
    {
        $date = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Get formatted date range.
     */
    public function getDateRangeAttribute(): string
    {
        return $this->start_date->format('d M Y') . ' - ' . $this->end_date->format('d M Y');
    }

    /**
     * Mark this financial year as the current one (and unmark others).
     */
    public function markAsCurrent(): void
    {
        // Unmark all other financial years for this company
        // Use created_by from the current record as fallback when no auth user
        $companyIds = auth()->check() ? getCompanyAndUsersId() : [$this->created_by];

        static::whereIn('created_by', $companyIds)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        // Mark this one as current
        $this->is_current = true;
        $this->save();
    }

    /**
     * Close the financial year.
     */
    public function close(): void
    {
        $this->status = 'closed';
        $this->is_current = false;
        $this->save();
    }
}
