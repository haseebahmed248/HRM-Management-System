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
        'status',
        'created_by',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'percentage_of_basic' => 'decimal:2',
        'type' => ComponentType::class,
        'is_taxable' => 'boolean',
        'is_mandatory' => 'boolean',
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
        return $this->componentType()->isCash();
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
