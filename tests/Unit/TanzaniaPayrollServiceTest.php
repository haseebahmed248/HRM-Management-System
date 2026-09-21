<?php

use App\Services\TanzaniaPayrollService;

/**
 * Unit tests for the Tanzania statutory calculator.
 *
 * Tests bypass the Setting/DB lookup by injecting an empty settings array
 * via Reflection, so every case runs against the hardcoded TRA defaults
 * baked into the service (2026 PAYE bands / NSSF 10+10 / SDL 3.5% / WCF 0.5%).
 *
 * Sample employees mirror the "3 sample employees" the client asked for
 * in the scope: one below the tax-free line, one mid-bracket, and one
 * hitting the top rate.
 */

function tzService(array $settings = []): TanzaniaPayrollService
{
    // Build an instance bypassing the DB-touching constructor so the unit
    // tests can run without a database. On PHP 8.1+ protected properties
    // are writable via Reflection without setAccessible().
    $svc  = new \ReflectionClass(TanzaniaPayrollService::class);
    $obj  = $svc->newInstanceWithoutConstructor();
    $prop = $svc->getProperty('settings');
    if (PHP_VERSION_ID < 80100) {
        $prop->setAccessible(true);
    }
    $prop->setValue($obj, $settings);
    return $obj;
}

// ─── PAYE ───────────────────────────────────────────────────────────────────

test('PAYE is zero below the tax-free threshold', function () {
    $svc = tzService();
    expect($svc->calculatePAYE(200000))->toBe(0.0);
    expect($svc->calculatePAYE(270000))->toBe(0.0);
});

test('PAYE applies 8% on the amount above 270000 up to 520000', function () {
    $svc = tzService();
    // 400,000: (400,000 - 270,000) * 8% = 10,400
    expect($svc->calculatePAYE(400000))->toBe(10400.0);
    // 520,000: (520,000 - 270,000) * 8% = 20,000
    expect($svc->calculatePAYE(520000))->toBe(20000.0);
});

test('PAYE stacks bands correctly at 760000 and 1000000', function () {
    $svc = tzService();
    // At 760,000: 20,000 + (760,000 - 520,000) * 20% = 20,000 + 48,000 = 68,000
    expect($svc->calculatePAYE(760000))->toBe(68000.0);
    // At 1,000,000: 68,000 + (1,000,000 - 760,000) * 25% = 68,000 + 60,000 = 128,000
    expect($svc->calculatePAYE(1000000))->toBe(128000.0);
});

test('PAYE tops out at 30% above 1,000,000', function () {
    $svc = tzService();
    // At 1,500,000: 128,000 + (1,500,000 - 1,000,000) * 30% = 128,000 + 150,000 = 278,000
    expect($svc->calculatePAYE(1500000))->toBe(278000.0);
});

// ─── NSSF ───────────────────────────────────────────────────────────────────

test('NSSF is 10% employee + 10% employer with no salary ceiling', function () {
    $svc = tzService();
    expect($svc->calculateNSSF(500000))->toBe([
        'employee' => 50000.0,
        'employer' => 50000.0,
    ]);
    // No ceiling — high earners scale linearly.
    expect($svc->calculateNSSF(5000000))->toBe([
        'employee' => 500000.0,
        'employer' => 500000.0,
    ]);
});

test('NSSF returns zero when employee is exempt', function () {
    $svc = tzService();
    expect($svc->calculateNSSF(500000, exempt: true))->toBe([
        'employee' => 0.0,
        'employer' => 0.0,
    ]);
});

// ─── SDL ────────────────────────────────────────────────────────────────────

test('SDL is not charged when the company has fewer than 10 employees', function () {
    $svc = tzService();
    expect($svc->calculateSDL(500000, employeeCount: 9))->toBe(0.0);
});

test('SDL is 3.5% of gross when the company has 10 or more employees', function () {
    $svc = tzService();
    expect($svc->calculateSDL(500000, employeeCount: 10))->toBe(17500.0);
    expect($svc->calculateSDL(1200000, employeeCount: 42))->toBe(42000.0);
});

test('SDL returns zero when employee is exempt', function () {
    $svc = tzService();
    expect($svc->calculateSDL(500000, employeeCount: 42, exempt: true))->toBe(0.0);
});

// ─── WCF ────────────────────────────────────────────────────────────────────

test('WCF is 0.5% of gross for every employee regardless of head-count', function () {
    $svc = tzService();
    expect($svc->calculateWCF(500000))->toBe(2500.0);
    expect($svc->calculateWCF(1200000))->toBe(6000.0);
});

test('WCF returns zero when employee is exempt', function () {
    $svc = tzService();
    expect($svc->calculateWCF(500000, exempt: true))->toBe(0.0);
});

// ─── Full payroll — 3 sample employees ──────────────────────────────────────

test('Sample employee 1 — junior on TSh 250,000 (below tax-free) in a 5-person shop', function () {
    // Below the PAYE threshold, no SDL (small shop), NSSF still applies, WCF still applies.
    $svc = tzService();
    $out = $svc->calculateFullPayroll(
        grossPay: 250000,
        employeeCount: 5,
    );

    expect($out['paye'])->toBe(0.0);
    expect($out['nssf_employee'])->toBe(25000.0);
    expect($out['nssf_employer'])->toBe(25000.0);
    expect($out['sdl'])->toBe(0.0); // <10 employees
    expect($out['wcf'])->toBe(1250.0);
    expect($out['total_deductions'])->toBe(25000.0);
    expect($out['net_pay'])->toBe(225000.0);
});

test('Sample employee 2 — mid-bracket on TSh 800,000 in a 15-person company', function () {
    $svc = tzService();
    $out = $svc->calculateFullPayroll(
        grossPay: 800000,
        employeeCount: 15,
    );

    // PAYE: 68,000 (up to 760k) + (800,000 - 760,000) * 25% = 68,000 + 10,000 = 78,000
    expect($out['paye'])->toBe(78000.0);
    expect($out['nssf_employee'])->toBe(80000.0);
    expect($out['nssf_employer'])->toBe(80000.0);
    expect($out['sdl'])->toBe(28000.0); // 800,000 * 3.5%
    expect($out['wcf'])->toBe(4000.0);
    expect($out['total_deductions'])->toBe(158000.0);
    expect($out['net_pay'])->toBe(642000.0);
});

test('Sample employee 3 — senior on TSh 2,000,000 in a 60-person company', function () {
    $svc = tzService();
    $out = $svc->calculateFullPayroll(
        grossPay: 2000000,
        employeeCount: 60,
    );

    // PAYE: 128,000 + (2,000,000 - 1,000,000) * 30% = 128,000 + 300,000 = 428,000
    expect($out['paye'])->toBe(428000.0);
    expect($out['nssf_employee'])->toBe(200000.0);
    expect($out['nssf_employer'])->toBe(200000.0);
    expect($out['sdl'])->toBe(70000.0);
    expect($out['wcf'])->toBe(10000.0);
    expect($out['total_deductions'])->toBe(628000.0);
    expect($out['net_pay'])->toBe(1372000.0);
});

// ─── Exemption flags are respected ─────────────────────────────────────────

test('Exemption flags zero out the matching component only', function () {
    $svc = tzService();
    $out = $svc->calculateFullPayroll(
        grossPay: 800000,
        employeeCount: 15,
        exemptNssf: true,
        exemptPaye: false,
        exemptSdl: true,
        exemptWcf: false,
    );

    expect($out['nssf_employee'])->toBe(0.0);
    expect($out['nssf_employer'])->toBe(0.0);
    expect($out['sdl'])->toBe(0.0);
    // PAYE still applies (78,000).
    expect($out['paye'])->toBe(78000.0);
    // WCF still applies.
    expect($out['wcf'])->toBe(4000.0);
    // Total deductions = only PAYE (NSSF exempt).
    expect($out['total_deductions'])->toBe(78000.0);
});

test('Non-taxable earnings reduce the PAYE base but not NSSF/WCF/SDL', function () {
    $svc = tzService();
    // Gross 500,000 with 100,000 non-taxable allowance.
    // PAYE base = 400,000 → (400,000 - 270,000) * 8% = 10,400
    // NSSF/WCF/SDL still calculated on 500,000.
    $out = $svc->calculateFullPayroll(
        grossPay: 500000,
        employeeCount: 20,
        nonTaxableEarnings: 100000,
    );

    expect($out['paye'])->toBe(10400.0);
    expect($out['paye_taxable_gross'])->toBe(400000.0);
    expect($out['nssf_employee'])->toBe(50000.0);
    expect($out['sdl'])->toBe(17500.0);
    expect($out['wcf'])->toBe(2500.0);
});
