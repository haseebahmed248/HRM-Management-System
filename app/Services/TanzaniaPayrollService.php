<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Tanzania payroll calculation service.
 *
 * Implements the current (2026) TRA/NSSF rules per the smartlinkerp.com
 * reference calculators supplied by the client:
 *
 *   PAYE  — progressive monthly bands. 2026 TRA rates.
 *   NSSF  — 10% employee + 10% employer of monthly gross. No salary ceiling.
 *   SDL   — 3.5% of monthly payroll for employers with 10+ employees only.
 *   WCF   — 0.5% of monthly payroll for workplace injury insurance.
 *
 * Same architectural pattern as ZambiaPayrollService: settings pulled from
 * the Super Admin's scope, with hardcoded TRA defaults so nothing breaks
 * if a key hasn't been set yet. Country routing lives in PayrollRun and
 * picks this service when the company country is Tanzania (TZ).
 */
class TanzaniaPayrollService
{
    protected array $settings;

    public function __construct(int $creatorId)
    {
        $scopeId = getSuperAdminId() ?? $creatorId;
        $this->settings = Setting::where('user_id', $scopeId)
            ->where('key', 'like', 'tanzania_%')
            ->pluck('value', 'key')
            ->toArray();
    }

    // ─── 1. PAYE ────────────────────────────────────────────────────────────

    /**
     * Tanzania PAYE on monthly gross using the current TRA bands.
     *
     * 2026 TRA bands (monthly TSh):
     *   0             — 270,000       0%
     *   270,001       — 520,000       8%   on excess over 270,000
     *   520,001       — 760,000       20,000 + 20% on excess over 520,000
     *   760,001       — 1,000,000     68,000 + 25% on excess over 760,000
     *   1,000,001+                    128,000 + 30% on excess over 1,000,000
     *
     * Bands are stored as (max, rate) pairs so the same "min == previous
     * band's max" method the Zambia service uses works verbatim. This keeps
     * the two calculators structurally identical and easy to test.
     */
    public function calculatePAYE(float $grossSalary): float
    {
        $slabs = [
            [
                'max'  => (float) ($this->settings['tanzania_paye_slab_1_max'] ?? 270000),
                'rate' => (float) ($this->settings['tanzania_paye_slab_1_rate'] ?? 0) / 100,
            ],
            [
                'max'  => (float) ($this->settings['tanzania_paye_slab_2_max'] ?? 520000),
                'rate' => (float) ($this->settings['tanzania_paye_slab_2_rate'] ?? 8) / 100,
            ],
            [
                'max'  => (float) ($this->settings['tanzania_paye_slab_3_max'] ?? 760000),
                'rate' => (float) ($this->settings['tanzania_paye_slab_3_rate'] ?? 20) / 100,
            ],
            [
                'max'  => (float) ($this->settings['tanzania_paye_slab_4_max'] ?? 1000000),
                'rate' => (float) ($this->settings['tanzania_paye_slab_4_rate'] ?? 25) / 100,
            ],
            [
                'max'  => (float) ($this->settings['tanzania_paye_slab_5_max'] ?? 999999999),
                'rate' => (float) ($this->settings['tanzania_paye_slab_5_rate'] ?? 30) / 100,
            ],
        ];

        $tax   = 0.0;
        $lower = 0.0;

        foreach ($slabs as $slab) {
            $upper = $slab['max'];
            if ($grossSalary > $lower) {
                $amountInBand = min($grossSalary, $upper) - $lower;
                if ($amountInBand > 0) {
                    $tax += $amountInBand * $slab['rate'];
                }
            }
            $lower = $upper;
        }

        return round($tax, 2);
    }

    // ─── 2. NSSF ────────────────────────────────────────────────────────────

    /**
     * NSSF is 10% employee + 10% employer of monthly gross with NO salary
     * ceiling per the current TRA/NSSF rule. Rates are configurable via the
     * Tanzania settings page in case the government changes them.
     */
    public function calculateNSSF(float $grossSalary, bool $exempt = false): array
    {
        if ($exempt) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $employeeRate = (float) ($this->settings['tanzania_nssf_employee_rate'] ?? 10) / 100;
        $employerRate = (float) ($this->settings['tanzania_nssf_employer_rate'] ?? 10) / 100;

        return [
            'employee' => round($grossSalary * $employeeRate, 2),
            'employer' => round($grossSalary * $employerRate, 2),
        ];
    }

    // ─── 3. SDL ─────────────────────────────────────────────────────────────

    /**
     * SDL (Skills Development Levy) is 3.5% of monthly payroll, employer only.
     * TRA rule: only applies when the employer has 10 or more employees.
     *
     * `$employeeCount` is the head-count for the pay period. Callers pass it
     * so the "10+ employees" gate is centralised here and can be tested.
     */
    public function calculateSDL(
        float $grossSalary,
        int $employeeCount,
        bool $exempt = false
    ): float {
        if ($exempt) {
            return 0.0;
        }

        $threshold = (int) ($this->settings['tanzania_sdl_employee_threshold'] ?? 10);
        if ($employeeCount < $threshold) {
            return 0.0;
        }

        $rate = (float) ($this->settings['tanzania_sdl_rate'] ?? 3.5) / 100;

        return round($grossSalary * $rate, 2);
    }

    // ─── 4. WCF ─────────────────────────────────────────────────────────────

    /**
     * WCF (Workers Compensation Fund) is 0.5% of monthly payroll, employer
     * only, unified rate across all sectors per the reference calculator.
     */
    public function calculateWCF(float $grossSalary, bool $exempt = false): float
    {
        if ($exempt) {
            return 0.0;
        }

        $rate = (float) ($this->settings['tanzania_wcf_rate'] ?? 0.5) / 100;

        return round($grossSalary * $rate, 2);
    }

    // ─── 5. Full payroll for one employee ───────────────────────────────────

    /**
     * Compute the full statutory breakdown for a single employee for a single
     * pay period. Same output shape as the Zambia service so downstream code
     * (payslip renderer, CSV export, reports) can consume either one.
     *
     * `$employeeCount` is the company head-count for this run; PayrollRun
     * passes the count of active employees it is processing.
     */
    public function calculateFullPayroll(
        float $grossPay,
        int $employeeCount,
        bool $exemptNssf = false,
        bool $exemptPaye = false,
        bool $exemptSdl = false,
        bool $exemptWcf = false,
        float $nonTaxableEarnings = 0.0,
        float $taxDeductibleDeductions = 0.0,
        float $notionalTaxableEarnings = 0.0
    ): array {
        $payeGross = max(
            0.0,
            $grossPay + $notionalTaxableEarnings - $nonTaxableEarnings - $taxDeductibleDeductions
        );
        $paye = $exemptPaye ? 0.0 : $this->calculatePAYE($payeGross);
        $nssf = $this->calculateNSSF($grossPay, $exemptNssf);
        $sdl  = $this->calculateSDL($grossPay, $employeeCount, $exemptSdl);
        $wcf  = $this->calculateWCF($grossPay, $exemptWcf);

        $totalDeductions = $paye + $nssf['employee'];
        $netPay          = $grossPay - $totalDeductions;

        return [
            'gross_pay'               => round($grossPay, 2),
            'paye'                    => $paye,
            'nssf_employee'           => $nssf['employee'],
            'total_deductions'        => round($totalDeductions, 2),
            'net_pay'                 => round($netPay, 2),
            'nssf_employer'           => $nssf['employer'],
            'sdl'                     => round($sdl, 2),
            'wcf'                     => round($wcf, 2),
            'non_taxable_earnings'    => round($nonTaxableEarnings, 2),
            'notional_taxable_earnings' => round($notionalTaxableEarnings, 2),
            'paye_taxable_gross'      => round($payeGross, 2),
            'employee_count'          => $employeeCount,
        ];
    }
}
