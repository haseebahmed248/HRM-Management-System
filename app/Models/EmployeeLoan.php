<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A salary advance or staff loan recovered after tax through payroll.
 *
 * Balance = amount - repayments. "completed" is derived from the balance and
 * never stored, so a loan reopens by itself if a run that recovered it is
 * unlocked and reprocessed.
 */
class EmployeeLoan extends Model
{
    public const TYPES = [
        'advance' => 'Salary Advance',
        'loan'    => 'Staff Loan',
    ];

    protected $fillable = [
        'employee_id',
        'loan_type',
        'reference',
        'description',
        'amount',
        'installments',
        'installment_amount',
        'disbursed_on',
        'start_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'installments'       => 'integer',
        'disbursed_on'       => 'date',
        'start_date'         => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function repayments()
    {
        return $this->hasMany(EmployeeLoanRepayment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeWithRepaid(Builder $query): Builder
    {
        return $query->withSum('repayments as repaid_total', 'amount');
    }

    public function repaidAmount(): float
    {
        $repaid = $this->repaid_total ?? $this->repayments()->sum('amount');

        return round((float) $repaid, 2);
    }

    public function balance(): float
    {
        return round(max(0.0, (float) $this->amount - $this->repaidAmount()), 2);
    }

    public function displayStatus(): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        return $this->balance() <= 0 ? 'completed' : $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->loan_type] ?? 'Loan';
    }

    /** Deduction line name. Kept per type (not per loan) so report columns stay stable. */
    public function recoveryLabel(): string
    {
        return $this->loan_type === 'advance' ? 'Salary Advance Recovery' : 'Staff Loan Recovery';
    }

    /**
     * Installment due this period. The last one takes whatever is left, so
     * rounding never leaves a few ngwee behind for an extra month.
     */
    public function dueInstallment(float $balance): float
    {
        $installment = (float) $this->installment_amount;
        if ($installment <= 0 || $balance - $installment < 1.0) {
            return round($balance, 2);
        }

        return round($installment, 2);
    }

    /** Active loans for one employee whose first deduction month has started by $periodEnd. */
    public static function dueForPeriod(int $employeeId, $periodEnd)
    {
        return static::withRepaid()
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $periodEnd)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (self $loan) => $loan->balance() > 0)
            ->values();
    }

    /** Next ADV-/LN- number across the whole company (all of its users). */
    public static function nextReference(string $type, array $companyUserIds): string
    {
        $prefix = $type === 'advance' ? 'ADV-' : 'LN-';
        $last = static::whereIn('created_by', $companyUserIds)
            ->where('reference', 'like', $prefix . '%')
            ->pluck('reference')
            ->map(fn ($ref) => (int) substr($ref, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
