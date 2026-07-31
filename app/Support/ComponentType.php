<?php

namespace App\Support;

enum ComponentType: string
{
    case Income = 'income';
    case Benefit = 'benefit';
    case DeductionTaxDeductible = 'deduction_tax_deductible';
    case DeductionNonTax = 'deduction_non_tax';
    case CompanyContribution = 'company_contribution';
    case Leave = 'leave';

    private const BEHAVIOURS = [
        'income' => [
            'label' => 'Income',
            'description' => 'Paid to the employee and included in taxable earnings.',
            'bucket' => 'earnings',
            'cash' => true,
            'taxable_effect' => 'increase',
        ],
        'benefit' => [
            'label' => 'Benefit',
            'description' => 'A taxable benefit; notional cash treatment is configured by processing rules.',
            'bucket' => 'earnings',
            'cash' => true,
            'taxable_effect' => 'increase',
        ],
        'deduction_tax_deductible' => [
            'label' => 'Deduction (Tax-deductible)',
            'description' => 'Reduces employee net pay and the PAYE taxable base.',
            'bucket' => 'deductions',
            'cash' => true,
            'taxable_effect' => 'reduce',
        ],
        'deduction_non_tax' => [
            'label' => 'Deduction (Non-Tax-deductible)',
            'description' => 'Reduces employee net pay after tax.',
            'bucket' => 'deductions',
            'cash' => true,
            'taxable_effect' => 'none',
        ],
        'company_contribution' => [
            'label' => 'Company Contribution',
            'description' => 'Paid by the company and never deducted from employee net pay.',
            'bucket' => 'employer_contributions',
            'cash' => false,
            'taxable_effect' => 'none',
        ],
        'leave' => [
            'label' => 'Leave',
            'description' => 'Leave pay treated as taxable employee income unless marked non-taxable.',
            'bucket' => 'earnings',
            'cash' => true,
            'taxable_effect' => 'increase',
        ],
    ];

    public function behavior(): array
    {
        return self::BEHAVIOURS[$this->value];
    }

    public function label(): string
    {
        return $this->behavior()['label'];
    }

    public function isEarning(): bool
    {
        return $this->behavior()['bucket'] === 'earnings';
    }

    public function isDeduction(): bool
    {
        return $this->behavior()['bucket'] === 'deductions';
    }

    public function isEmployerContribution(): bool
    {
        return $this->behavior()['bucket'] === 'employer_contributions';
    }

    public function reducesTaxableBase(): bool
    {
        return $this->behavior()['taxable_effect'] === 'reduce';
    }

    public function increasesTaxableBase(): bool
    {
        return $this->behavior()['taxable_effect'] === 'increase';
    }

    public function isCash(): bool
    {
        return $this->behavior()['cash'];
    }

    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    public static function earningValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->isEarning())
        ));
    }

    public static function deductionValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->isDeduction())
        ));
    }

    public static function options(): array
    {
        return array_map(function (self $type) {
            $behavior = $type->behavior();

            return [
                'value' => $type->value,
                'label' => $behavior['label'],
                'description' => $behavior['description'],
                'bucket' => $behavior['bucket'],
                'taxable_effect' => $behavior['taxable_effect'],
            ];
        }, self::cases());
    }

    public static function normalizeInput(?string $value, ?string $calculationType = null): ?self
    {
        return match ($value) {
            'earning' => self::Income,
            'deduction' => $calculationType === 'zambia_pension'
                ? self::DeductionTaxDeductible
                : self::DeductionNonTax,
            default => self::tryFrom((string) $value),
        };
    }

    public static function isEmployerContributionLine(array $line): bool
    {
        if (array_key_exists('is_employer_contribution', $line)) {
            return (bool) $line['is_employer_contribution'];
        }

        return in_array($line['type'] ?? null, [
            'zambia_napsa_employer',
            'zambia_nhima_employer',
            'zambia_sdl',
            self::CompanyContribution->value,
        ], true);
    }
}
