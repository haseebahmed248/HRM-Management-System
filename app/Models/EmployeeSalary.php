<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSalary extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'basic_salary',
        'rate_type',
        'effective_from',
        'components',
        'is_active',
        'calculation_status',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'components' => 'array',
        'is_active' => 'boolean',
        'effective_from' => 'date',
    ];

    /**
     * Item 9 - Employee rate details. Derives every equivalent rate (hourly,
     * daily, weekly, monthly) from the stored rate + rate type, plus the monthly
     * notional pay and the leave pay rate (paid at the daily rate). Uses the
     * company's hours/day, working days/week and working days/month config.
     */
    public function rateBreakdown(float $hoursPerDay = 8.0, int $daysPerWeek = 5, int $daysPerMonth = 22): array
    {
        $rate       = (float) $this->basic_salary;
        $type       = $this->rate_type ?? 'monthly';
        $perWeek    = max(1, $daysPerWeek);
        $perMonth   = max(1, $daysPerMonth);

        // Convert the stored rate to a monthly-equivalent (notional) figure first.
        $monthly = match ($type) {
            'hourly'      => $rate * $hoursPerDay * $perMonth,
            'daily'       => $rate * $perMonth,
            'weekly'      => $rate * ($perMonth / $perWeek),
            'fortnightly' => $rate * ($perMonth / $perWeek / 2),
            default       => $rate, // monthly
        };

        $daily  = $monthly / $perMonth;
        $hourly = $hoursPerDay > 0 ? $daily / $hoursPerDay : 0;
        $weekly = $daily * $perWeek;

        return [
            'rate_type'        => $type,
            'configured_rate'  => round($rate, 4),
            'hourly_rate'      => round($hourly, 4),
            'daily_rate'       => round($daily, 4),
            'weekly_rate'      => round($weekly, 4),
            'monthly_rate'     => round($monthly, 2),
            'notional_pay'     => round($monthly, 2),      // monthly-equivalent wage
            'leave_pay_rate'   => round($daily, 2),        // leave paid at the daily rate
        ];
    }

    /**
     * Item 7 - convert the stored rate into the base pay for a given pay period.
     * 'monthly' returns basic_salary unchanged (existing behaviour). Other rate
     * types multiply the rate by the units worked in the period.
     *
     * @param  int    $workingDays        scheduled working days in the period
     * @param  int    $workingDaysPerWeek working days in a normal week (e.g. 5)
     * @param  float  $hoursPerDay        contracted hours per day (default 8)
     */
    public function basePayForPeriod(int $workingDays, int $workingDaysPerWeek, float $hoursPerDay = 8.0): float
    {
        $rate = (float) $this->basic_salary;
        $type = $this->rate_type ?? 'monthly';
        $perWeek = $workingDaysPerWeek > 0 ? $workingDaysPerWeek : 5;

        return match ($type) {
            'hourly'      => round($rate * $workingDays * $hoursPerDay, 2),
            'daily'       => round($rate * $workingDays, 2),
            'weekly'      => round($rate * ($workingDays / $perWeek), 2),
            'fortnightly' => round($rate * ($workingDays / $perWeek / 2), 2),
            default       => round($rate, 2), // monthly - unchanged
        };
    }

    /**
     * Get the employee.
     */
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id','id');
    }



    /**
     * Get the user who created the salary.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get active salary for employee.
     */
    public static function getActiveSalary($employeeId)
    {
        return static::where('employee_id', $employeeId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get basic salary for employee.
     */
    public static function getBasicSalary($employeeId)
    {
        $salary = static::getActiveSalary($employeeId);
        return $salary ? $salary->basic_salary : 0;
    }

    /**
     * Normalise the stored components value into a consistent structure:
     *   [ ['id' => 1, 'amount' => 300.00], ... ]
     *
     * Supports two legacy formats transparently:
     *   - Old plain-ID array : [1, 2, 3]
     *   - New object array   : [{'id':1,'amount':300}, ...]
     */
    public function getNormalisedComponents(): array
    {
        $raw = $this->components ?? [];

        if (empty($raw)) {
            return [];
        }

        // Already in new format?
        if (is_array($raw[0] ?? null) && isset($raw[0]['id'])) {
            return $raw;
        }

        // Old plain-ID format — convert, amount = null means use component default
        return array_map(fn($id) => ['id' => (int) $id, 'amount' => null], $raw);
    }

    /**
     * Calculate salary components based on selected components.
     *
     * Supports per-employee amount overrides: if an 'amount' key is present
     * and non-null in the stored component record, that value is used instead
     * of the component's default_amount / percentage_of_basic.
     */
    public function calculateAllComponents(array $processingContext = [])
    {
        $normalisedComponents = $this->getNormalisedComponents();
        $componentIds         = array_column($normalisedComponents, 'id');

        $components = SalaryComponent::whereIn('id', $componentIds)
            ->where('status', 'active')
            ->whereIn('created_by', getCompanyAndUsersId())
            ->get()
            ->keyBy('id');

        // Build a quick lookup of custom amounts
        $customAmounts = [];
        foreach ($normalisedComponents as $entry) {
            if (isset($entry['amount']) && $entry['amount'] !== null && $entry['amount'] !== '') {
                $customAmounts[(int) $entry['id']] = (float) $entry['amount'];
            }
        }

        $earnings        = ['Basic Salary' => $this->basic_salary];
        $deductions      = [];
        $employerContributions = [];
        $componentLines  = [];
        $totalEarnings   = $this->basic_salary;
        $totalDeductions = 0;
        $totalEmployerContributions = 0;
        $totalNotionalPay = 0;

        foreach ($normalisedComponents as $entry) {
            $id        = (int) $entry['id'];
            $component = $components->get($id);

            if (!$component) {
                continue;
            }

            if (! $this->componentAppliesForPeriod($component, $processingContext)) {
                continue;
            }

            // Use the custom per-employee amount when provided, otherwise fall
            // back to the component's own calculation (percentage or fixed).
            $amount = array_key_exists($id, $customAmounts)
                ? $customAmounts[$id]
                : $component->calculateAmount($this->basic_salary);

            $prorationFactor = $component->pro_rata_start_end
                ? max(0.0, min(1.0, (float) ($processingContext['proration_factor'] ?? 1.0)))
                : 1.0;
            $amount = round((float) $amount * $prorationFactor, 2);

            $componentType = $component->componentType();
            $line = [
                'component_id' => $component->id,
                'name' => $component->name,
                'amount' => round((float) $amount, 2),
                'type' => $componentType->value,
                'calculation_type' => $component->calculation_type,
                'is_taxable' => (bool) $component->is_taxable,
                'is_earning' => $component->isEarning(),
                'is_deduction' => $component->isDeduction(),
                'is_employer_contribution' => $component->isEmployerContribution(),
                'reduces_taxable_base' => $component->reducesTaxableBase(),
                'increases_taxable_base' => $component->increasesTaxableBase(),
                'is_cash' => $component->isCash(),
                'is_notional' => (bool) $component->affect_notional_pay,
                'affect_payslip' => (bool) $component->affect_payslip,
                'print_on_payslip' => (bool) $component->print_on_payslip,
                'pro_rata_start_end' => (bool) $component->pro_rata_start_end,
                'proration_factor' => $prorationFactor,
                'compulsory_deduction' => (bool) $component->compulsory_deduction,
                'clear_totals' => $component->clear_totals,
            ];
            $componentLines[] = $line;

            if ($component->isEarning()) {
                if ($component->isCash()) {
                    $earnings[$component->name] = $amount;
                    $totalEarnings += $amount;
                } else {
                    $totalNotionalPay += $amount;
                }
            } elseif ($component->isDeduction()) {
                $deductions[$component->name] = $amount;
                $totalDeductions += $amount;
            } elseif ($component->isEmployerContribution()) {
                $employerContributions[$component->name] = $amount;
                $totalEmployerContributions += $amount;
            }
        }

        return [
            'basic_salary'    => $this->basic_salary,
            'earnings'        => $earnings,
            'deductions'      => $deductions,
            'employer_contributions' => $employerContributions,
            'component_lines' => $componentLines,
            'total_earnings'  => $totalEarnings,
            'total_deductions'=> $totalDeductions,
            'total_employer_contributions' => $totalEmployerContributions,
            'total_notional_pay' => $totalNotionalPay,
            'gross_salary'    => $totalEarnings,
            'net_salary'      => $totalEarnings - $totalDeductions,
        ];
    }

    private function componentAppliesForPeriod(SalaryComponent $component, array $context): bool
    {
        if (empty($context['period_start']) || empty($context['period_end'])) {
            return true;
        }

        $periodStart = Carbon::parse($context['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($context['period_end'])->endOfDay();
        $effectiveFrom = $this->effective_from
            ? Carbon::parse($this->effective_from)->startOfDay()
            : Carbon::parse($this->created_at ?? $periodStart)->startOfDay();

        if ($periodEnd->lt($effectiveFrom)) {
            return false;
        }

        $monthIndex = (int) $effectiveFrom->copy()->startOfMonth()
            ->diffInMonths($periodStart->copy()->startOfMonth(), false);
        $monthIndex = max(0, $monthIndex);
        $months = max(0, (int) ($component->delay_months ?? 0));

        return match ($component->delay_type ?? 'none') {
            'delay_for' => $monthIndex >= $months,
            'use_for_next' => $monthIndex < $months,
            default => true,
        };
    }
}
