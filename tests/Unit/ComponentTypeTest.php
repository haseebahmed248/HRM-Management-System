<?php

use App\Support\ComponentType;

test('component type behavior map covers all six payroll types', function () {
    expect(ComponentType::values())->toBe([
        'income',
        'benefit',
        'deduction_tax_deductible',
        'deduction_non_tax',
        'company_contribution',
        'leave',
    ])
        ->and(ComponentType::Income->isEarning())->toBeTrue()
        ->and(ComponentType::Benefit->increasesTaxableBase())->toBeTrue()
        ->and(ComponentType::DeductionTaxDeductible->isDeduction())->toBeTrue()
        ->and(ComponentType::DeductionTaxDeductible->reducesTaxableBase())->toBeTrue()
        ->and(ComponentType::DeductionNonTax->reducesTaxableBase())->toBeFalse()
        ->and(ComponentType::CompanyContribution->isEmployerContribution())->toBeTrue()
        ->and(ComponentType::CompanyContribution->isCash())->toBeFalse()
        ->and(ComponentType::Leave->isEarning())->toBeTrue();
});

test('legacy values normalize without losing pension relief', function () {
    expect(ComponentType::normalizeInput('earning'))->toBe(ComponentType::Income)
        ->and(ComponentType::normalizeInput('deduction', 'fixed'))->toBe(ComponentType::DeductionNonTax)
        ->and(ComponentType::normalizeInput('deduction', 'zambia_pension'))->toBe(ComponentType::DeductionTaxDeductible);
});
