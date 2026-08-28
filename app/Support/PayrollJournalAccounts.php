<?php

namespace App\Support;

/**
 * GL account codes used by the Payroll Summary Journal for the fixed lines
 * (Basic Pay, net pay, and the statutory payables/expenses). Per-component
 * earnings/deductions carry their own account_code; these cover everything
 * that is not a component. Stored per company in the `payroll_journal_accounts`
 * setting; falls back to a sensible default chart of accounts.
 */
class PayrollJournalAccounts
{
    /** Editable code keys shown on the settings screen, in display order. */
    public const EDITABLE = [
        'basic_pay'         => 'Basic Pay',
        'direct_labour'     => 'Direct Labour (grouped)',
        'net_salary'        => 'Net Salary Payable',
        'paye'              => 'PAYE Payable',
        'napsa_payable'     => 'NAPSA Payable (Employee + Employer)',
        'napsa_emr_expense' => 'NAPSA Employer Expense',
        'nhima_payable'     => 'NHIMA Payable (Employee + Employer)',
        'nhima_emr_expense' => 'NHIMA Employer Expense',
        'sdl_payable'       => 'SDL Payable',
        'sdl_expense'       => 'SDL Employer Expense',
        'other_deductions'  => 'Other Deductions / Employee Advances',
        'earnings_fallback' => 'Default Earning Code (unmapped components)',
    ];

    public static function defaults(): array
    {
        return [
            'basic_pay'         => '600-110',
            'direct_labour'     => '600-105',
            'net_salary'        => '200-050',
            'paye'              => '200-100',
            'napsa_payable'     => '200-110',
            'napsa_emr_expense' => '600-400',
            'nhima_payable'     => '200-120',
            'nhima_emr_expense' => '600-440',
            'sdl_payable'       => '200-140',
            'sdl_expense'       => '600-450',
            'other_deductions'  => '200-130',
            'earnings_fallback' => '600-100',
            // Keyword fallback for earning components with no account_code of their
            // own (matched against the lowercased component name). Not shown on the
            // settings screen — components set their own codes on the component form.
            'earnings' => [
                'basic'      => '600-110',
                'housing'    => '600-120',
                'transport'  => '600-130',
                'expatriate' => '600-140',
                'car'        => '600-150',
                'leave'      => '600-160',
                'lunch'      => '600-170',
                'commission' => '600-180',
                'overtime'   => '600-190',
                'gratuity'   => '600-200',
            ],
        ];
    }

    public static function forCompany(int $companyId): array
    {
        $stored = getSetting('payroll_journal_accounts', '', $companyId);
        if (! is_string($stored) || trim($stored) === '') {
            return self::defaults();
        }
        $decoded = json_decode($stored, true);

        return is_array($decoded) ? self::normalize($decoded) : self::defaults();
    }

    /** Merge submitted editable codes over the defaults; keep the keyword map intact. */
    public static function normalize(array $input): array
    {
        $codes = self::defaults();
        foreach (array_keys(self::EDITABLE) as $key) {
            $val = $input[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $codes[$key] = trim($val);
            }
        }

        return $codes;
    }

    /** Just the editable codes (for the settings screen). */
    public static function editableForCompany(int $companyId): array
    {
        $all = self::forCompany($companyId);

        return array_intersect_key($all, self::EDITABLE);
    }
}
