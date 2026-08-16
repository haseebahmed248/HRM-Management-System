<?php

namespace App\Services;

use App\Models\LeaveAccrual;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;

/**
 * Accrues leave for the employees on a payroll run, driven by each leave
 * policy's accrual configuration (allocation_type = 'accrual').
 *
 *  - Monthly policies accrue `accrual_rate` days once per pay-period month.
 *  - Yearly policies accrue once per year (month = 0 in the ledger).
 *  - If no rate is set, it falls back to leave type max_days_per_year / 12
 *    (monthly) or the full annual max (yearly).
 *  - Accrual is added to allocated_days and capped at the leave type's annual
 *    maximum; remaining_days is recalculated via the canonical formula.
 *  - A leave_accruals ledger row guarantees the accrual runs at most once per
 *    (employee, leave type, period) — so re-approving/re-running never doubles.
 */
class LeaveAccrualService
{
    public function accrueForPayrollRun(PayrollRun $run): array
    {
        $companyIds = getCompanyAndUsersId();
        $period = $run->pay_period_end ?? $run->pay_period_start;
        if (!$period) {
            return ['accrued' => 0, 'skipped' => 0];
        }
        $year  = (int) $period->format('Y');
        $month = (int) $period->format('n');

        $policies = LeavePolicy::with('leaveType')
            ->whereIn('created_by', $companyIds)
            ->where('status', 'active')
            ->where('allocation_type', 'accrual')
            ->get();

        if ($policies->isEmpty()) {
            return ['accrued' => 0, 'skipped' => 0];
        }

        $employeeIds = PayrollEntry::where('payroll_run_id', $run->id)
            ->pluck('employee_id')
            ->unique();

        $creator = creatorId();
        $accrued = 0;
        $skipped = 0;

        foreach ($employeeIds as $employeeId) {
            foreach ($policies as $policy) {
                $leaveType = $policy->leaveType;
                if (!$leaveType) {
                    continue;
                }

                $isYearly    = ($policy->accrual_type === 'yearly');
                $ledgerMonth = $isYearly ? 0 : $month;
                $maxPerYear  = (float) ($leaveType->max_days_per_year ?? 0);

                // Resolve the accrual amount for this period.
                $rate = (float) ($policy->accrual_rate ?? 0);
                if ($rate <= 0) {
                    $rate = $isYearly ? $maxPerYear : ($maxPerYear > 0 ? $maxPerYear / 12 : 0);
                }
                if ($rate <= 0) {
                    continue;
                }

                // Idempotency: one accrual per employee/type/period.
                $already = LeaveAccrual::where('employee_id', $employeeId)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('year', $year)
                    ->where('month', $ledgerMonth)
                    ->exists();
                if ($already) {
                    $skipped++;
                    continue;
                }

                $balance = LeaveBalance::firstOrCreate(
                    [
                        'employee_id'   => $employeeId,
                        'leave_type_id' => $leaveType->id,
                        'year'          => $year,
                    ],
                    [
                        'leave_policy_id'   => $policy->id,
                        'allocated_days'    => 0,
                        'used_days'         => 0,
                        'carried_forward'   => 0,
                        'manual_adjustment' => 0,
                        'remaining_days'    => 0,
                        'created_by'        => $creator,
                    ]
                );

                // Accrue into allocated, capped at the annual entitlement.
                $current      = (float) $balance->allocated_days;
                $newAllocated = $maxPerYear > 0 ? min($maxPerYear, $current + $rate) : ($current + $rate);
                $actual       = round($newAllocated - $current, 2);

                $balance->allocated_days = $newAllocated;
                $balance->calculateRemainingDays();
                $balance->save();

                LeaveAccrual::create([
                    'employee_id'      => $employeeId,
                    'leave_type_id'    => $leaveType->id,
                    'leave_balance_id' => $balance->id,
                    'payroll_run_id'   => $run->id,
                    'year'             => $year,
                    'month'            => $ledgerMonth,
                    'days'             => $actual,
                    'created_by'       => $creator,
                ]);
                $accrued++;
            }
        }

        return ['accrued' => $accrued, 'skipped' => $skipped];
    }
}
