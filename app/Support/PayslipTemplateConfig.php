<?php

namespace App\Support;

class PayslipTemplateConfig
{
    public const EMPLOYEE_FIELD_KEYS = [
        'employee_name',
        'nrc',
        'designation',
        'pay_period',
        'tpin',
        'napsa_number',
        'nhima_number',
        'date_of_joining',
        'bank_name',
        'account_number',
    ];

    public static function defaults(): array
    {
        return [
            'logo' => ['show' => true],
            'qr' => ['show' => true],
            'header' => [
                'title' => 'Salary Slip',
                'show_company_email' => true,
                'show_company_phone' => true,
            ],
            'footer' => [
                'text' => 'This is a computer-generated payslip and does not require a signature.',
            ],
            'sections' => [
                'employee_info' => true,
                'leave' => true,
                'earnings_deductions' => true,
                'employer_contributions' => true,
            ],
            'employee_fields' => [
                ['key' => 'employee_name', 'label' => 'Employee Name', 'show' => true],
                ['key' => 'nrc', 'label' => 'NRC No', 'show' => true],
                ['key' => 'designation', 'label' => 'Designation', 'show' => true],
                ['key' => 'pay_period', 'label' => 'Pay Period', 'show' => true],
                ['key' => 'tpin', 'label' => 'TPIN (Tax ID)', 'show' => true],
                ['key' => 'napsa_number', 'label' => 'NAPSA Number', 'show' => true],
                ['key' => 'nhima_number', 'label' => 'NHIMA Number', 'show' => true],
                ['key' => 'date_of_joining', 'label' => 'Date Of Joining', 'show' => true],
                ['key' => 'bank_name', 'label' => 'Bank Name', 'show' => true],
                ['key' => 'account_number', 'label' => 'Account Number', 'show' => true],
            ],
        ];
    }

    public static function forCompany(int $companyId): array
    {
        $stored = getSetting('payslip_template_config', '', $companyId);
        if (! is_string($stored) || trim($stored) === '') {
            return self::defaults();
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? self::normalize($decoded) : self::defaults();
    }

    public static function normalize(array $config): array
    {
        $defaults = self::defaults();
        $normalized = array_replace_recursive($defaults, array_intersect_key($config, $defaults));

        $submittedFields = collect($config['employee_fields'] ?? [])
            ->filter(fn ($field) => is_array($field) && in_array($field['key'] ?? null, self::EMPLOYEE_FIELD_KEYS, true))
            ->keyBy('key');

        $normalized['employee_fields'] = collect($defaults['employee_fields'])
            ->map(function (array $defaultField) use ($submittedFields) {
                $submitted = $submittedFields->get($defaultField['key'], []);

                return [
                    'key' => $defaultField['key'],
                    'label' => trim((string) ($submitted['label'] ?? $defaultField['label'])) ?: $defaultField['label'],
                    'show' => filter_var($submitted['show'] ?? $defaultField['show'], FILTER_VALIDATE_BOOLEAN),
                ];
            })
            ->values()
            ->all();

        foreach (['logo', 'qr'] as $group) {
            $normalized[$group]['show'] = filter_var($normalized[$group]['show'], FILTER_VALIDATE_BOOLEAN);
        }

        foreach (['show_company_email', 'show_company_phone'] as $key) {
            $normalized['header'][$key] = filter_var($normalized['header'][$key], FILTER_VALIDATE_BOOLEAN);
        }

        foreach (array_keys($defaults['sections']) as $key) {
            $normalized['sections'][$key] = filter_var($normalized['sections'][$key], FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }
}
