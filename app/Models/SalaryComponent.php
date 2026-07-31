<?php

namespace App\Models;

use App\Support\ComponentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalaryComponent extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'calculation_type',
        'default_amount',
        'percentage_of_basic',
        'is_taxable',
        'is_mandatory',
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
        'status',
        'created_by',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'percentage_of_basic' => 'decimal:2',
        'type' => ComponentType::class,
        'is_taxable' => 'boolean',
        'is_mandatory' => 'boolean',
        'affect_notional_pay' => 'boolean',
        'affect_payslip' => 'boolean',
        'print_on_payslip' => 'boolean',
        'pro_rata_start_end' => 'boolean',
        'compulsory_deduction' => 'boolean',
        'delay_months' => 'integer',
        'clear_specific_month' => 'integer',
        'cycle_start_date' => 'date:Y-m-d',
        'cycle_length_months' => 'integer',
    ];

    /**
     * Get the user who created the component.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate component amount based on basic salary.
     */
    public function calculateAmount($basicSalary = 0)
    {
        if ($this->calculation_type === 'percentage' && $this->percentage_of_basic) {
            return ($basicSalary * $this->percentage_of_basic) / 100;
        }
        
        return $this->default_amount;
    }

    public function componentType(): ComponentType
    {
        return $this->type instanceof ComponentType
            ? $this->type
            : ComponentType::from($this->type);
    }

    public function isEarning(): bool
    {
        return $this->componentType()->isEarning();
    }

    public function isDeduction(): bool
    {
        return $this->componentType()->isDeduction();
    }

    public function isEmployerContribution(): bool
    {
        return $this->componentType()->isEmployerContribution();
    }

    public function reducesTaxableBase(): bool
    {
        return $this->componentType()->reducesTaxableBase();
    }

    public function increasesTaxableBase(): bool
    {
        return $this->componentType()->increasesTaxableBase();
    }

    public function isCash(): bool
    {
        return $this->componentType()->isCash()
            && ! ($this->isEarning() && $this->affect_notional_pay);
    }

    /**
     * Get earnings components.
     */
    public static function getEarnings()
    {
        return static::whereIn('type', ComponentType::earningValues())
            ->where('status', 'active')
            ->whereIn('created_by', getCompanyAndUsersId())
            ->get();
    }

    /**
     * Get deductions components.
     */
    public static function getDeductions()
    {
        return static::whereIn('type', ComponentType::deductionValues())
            ->where('status', 'active')
            ->whereIn('created_by', getCompanyAndUsersId())
            ->get();
    }

    public static function getEmployerContributions()
    {
        return static::where('type', ComponentType::CompanyContribution->value)
            ->where('status', 'active')
            ->whereIn('created_by', getCompanyAndUsersId())
            ->get();
    }
}
