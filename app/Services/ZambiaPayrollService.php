<?php

namespace App\Services;

use App\Models\Setting;

class ZambiaPayrollService
{
    protected array $settings;

    public function __construct(int $creatorId)
    {
        // Zambia tax settings are controlled globally by the Super Admin, so the
        // payroll calc always reads the Super Admin's values (falling back to the
        // given creator id only if no Super Admin exists). Hardcoded ZRA defaults
        // in each calc method cover any key the Super Admin hasn't set.
        $scopeId = getSuperAdminId() ?? $creatorId;
        $this->settings = Setting::where('user_id', $scopeId)
            ->where('key', 'like', 'zambia_%')
            ->pluck('value', 'key')
            ->toArray();
    }

    // ─── 1. PAYE ────────────────────────────────────────────────────────────

    /**
     * track-a/10: PAYE now accepts an optional employee pension contribution.
     * When supplied, the contribution is capped at `zambia_pension_relief_cap`
     * (default K1,000/month) and SUBTRACTED from gross before the existing
     * PAYE bands apply. This implements ZRA's pension tax-relief rule.
     *
     * Backward compatible: callers that omit the second argument compute
     * exactly the same way as before this change.
     */
    public function calculatePAYE(float $grossSalary, float $pensionContribution = 0.0): float
    {
        // Apply pension relief BEFORE bands. Cap configurable per-tenant via
        // the Zambia tax settings page; default K1,000/month.
        $reliefCap = (float) ($this->settings['zambia_pension_relief_cap'] ?? 1000);
        $relief    = max(0.0, min($pensionContribution, $reliefCap));
        $taxableIncome = max(0.0, $grossSalary - $relief);

        // PAYE bands are contiguous: each band's lower bound is the previous
        // band's ceiling (`max`). We compute the amount of income falling inside
        // each band as `min(income, thisMax) - previousMax`. This matches the ZRA
        // PAYE method exactly (0 / 20% / 30% / 37% on the 5,100 / 7,100 / 9,200
        // thresholds) and is independent of how the "min" boundary is entered
        // (5100, 5100.01 or 5101 all give the same, correct result).
        $slabs = [
            [
                'max'  => (float) ($this->settings['zambia_paye_slab_1_max'] ?? 5100),
                'rate' => (float) ($this->settings['zambia_paye_slab_1_rate'] ?? 0) / 100,
            ],
            [
                'max'  => (float) ($this->settings['zambia_paye_slab_2_max'] ?? 7100),
                'rate' => (float) ($this->settings['zambia_paye_slab_2_rate'] ?? 20) / 100,
            ],
            [
                'max'  => (float) ($this->settings['zambia_paye_slab_3_max'] ?? 9200),
                'rate' => (float) ($this->settings['zambia_paye_slab_3_rate'] ?? 30) / 100,
            ],
            [
                'max'  => (float) ($this->settings['zambia_paye_slab_4_max'] ?? 999999999),
                'rate' => (float) ($this->settings['zambia_paye_slab_4_rate'] ?? 37) / 100,
            ],
        ];

        $tax   = 0.0;
        $lower = 0.0;

        foreach ($slabs as $slab) {
            $upper = $slab['max'];
            if ($taxableIncome > $lower) {
                $amountInBand = min($taxableIncome, $upper) - $lower;
                if ($amountInBand > 0) {
                    $tax += $amountInBand * $slab['rate'];
                }
            }
            $lower = $upper;
        }

        return round($tax, 2);
    }

    /**
     * track-a/10: expose the configured relief cap so callers (Inertia pages,
     * payroll breakdown, etc.) can display "K1,000 cap applied" or similar.
     */
    public function pensionReliefCap(): float
    {
        return (float) ($this->settings['zambia_pension_relief_cap'] ?? 1000);
    }

    // ─── 2. NAPSA ───────────────────────────────────────────────────────────

    public function calculateNAPSA(float $grossSalary, bool $exempt = false): array
    {
        // If employee is exempt, return zero contributions
        if ($exempt) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $employeeRate = (float) ($this->settings['zambia_napsa_employee_rate'] ?? 5) / 100;
        $employerRate = (float) ($this->settings['zambia_napsa_employer_rate'] ?? 5) / 100;
        $cap          = (float) ($this->settings['zambia_napsa_monthly_cap']   ?? 1073.20);

        $employeeContribution = min($grossSalary * $employeeRate, $cap);
        $employerContribution = min($grossSalary * $employerRate, $cap);

        return [
            'employee' => round($employeeContribution, 2),
            'employer' => round($employerContribution, 2),
        ];
    }

    // ─── 3. NHIMA ───────────────────────────────────────────────────────────
    // FIX: NHIMA must be calculated on BASIC SALARY only, not gross pay

    public function calculateNHIMA(float $basicSalary, bool $exempt = false): array
    {
        // If employee is exempt, return zero contributions
        if ($exempt) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $employeeRate = (float) ($this->settings['zambia_nhima_employee_rate'] ?? 1) / 100;
        $employerRate = (float) ($this->settings['zambia_nhima_employer_rate'] ?? 1) / 100;

        return [
            'employee' => round($basicSalary * $employeeRate, 2),
            'employer' => round($basicSalary * $employerRate, 2),
        ];
    }

    // ─── 4. SDL ─────────────────────────────────────────────────────────────

    public function calculateSDL(float $totalPayroll, bool $exempt = false): float
    {
        if ($exempt) {
            return 0.0;
        }

        $rate = (float) ($this->settings['zambia_sdl_rate'] ?? 0.5) / 100;

        return round($totalPayroll * $rate, 2);
    }

    // ─── 5. Full payroll for one employee ───────────────────────────────────
    // Now accepts basicSalary separately for NHIMA fix
    // And exemption flags for NAPSA/NHIMA

    public function calculateFullPayroll(
        float $grossPay,
        float $basicSalary = 0,
        bool $exemptNapsa = false,
        bool $exemptNhima = false,
        float $pensionContribution = 0.0,
        bool $exemptPaye = false,
        float $nonTaxableEarnings = 0.0
    ): array {
        // track-a/10: forward pension contribution into PAYE so the existing
        // cap-and-subtract logic applies. PAYE is the only statutory tax
        // that gets the relief — NAPSA / NHIMA / SDL still use raw gross.
        // An employee flagged exempt_from_paye pays zero PAYE.
        //
        // Non-taxable income components (salary components with is_taxable = false)
        // are excluded from the PAYE base only: the employee still receives them
        // in gross and net pay, and NAPSA / NHIMA are unaffected (they use raw
        // gross / basic). This is the standard treatment of a tax-free allowance.
        $payeGross = max(0.0, $grossPay - $nonTaxableEarnings);
        $paye  = $exemptPaye ? 0.0 : $this->calculatePAYE($payeGross, $pensionContribution);
        $napsa = $this->calculateNAPSA($grossPay, $exemptNapsa);

        // ── NHIMA fix: use basicSalary, fallback to grossPay if not provided ──
        $nhimaBase = $basicSalary > 0 ? $basicSalary : $grossPay;
        $nhima     = $this->calculateNHIMA($nhimaBase, $exemptNhima);

        $totalDeductions = $paye + $napsa['employee'] + $nhima['employee'];
        $netPay          = $grossPay - $totalDeductions;

        // track-a/10: compute the actual relief applied (capped) so the
        // caller can show "K1,000 cap applied" in payslips / breakdowns.
        $reliefCap        = $this->pensionReliefCap();
        $pensionRelief    = max(0.0, min($pensionContribution, $reliefCap));

        return [
            'gross_pay'           => round($grossPay, 2),
            'paye'                => $paye,
            'napsa_employee'      => $napsa['employee'],
            'nhima_employee'      => $nhima['employee'],
            'total_deductions'    => round($totalDeductions, 2),
            'net_pay'             => round($netPay, 2),
            'napsa_employer'      => $napsa['employer'],
            'nhima_employer'      => $nhima['employer'],
            // track-a/10: surfaced for payslip transparency
            'pension_contribution' => round($pensionContribution, 2),
            'pension_relief'       => round($pensionRelief, 2),
            'pension_relief_cap'   => round($reliefCap, 2),
            // Non-taxable income handling: what was excluded from the PAYE base.
            'non_taxable_earnings' => round($nonTaxableEarnings, 2),
            'paye_taxable_gross'   => round($payeGross, 2),
        ];
    }
}