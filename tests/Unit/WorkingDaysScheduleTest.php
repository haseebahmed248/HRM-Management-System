<?php

use App\Models\EmployeeSalary;
use App\Models\PayrollRun;

test('configured monthly working days do not depend on calendar dates', function () {
    $schedule = PayrollRun::resolveWorkSchedule([
        'working_days' => '[1,2,3,4,5,6]',
        'hours_per_day' => '8',
        'working_days_per_month' => '26',
    ]);

    expect($schedule)->toMatchArray([
        'working_days' => [1, 2, 3, 4, 5, 6],
        'days_per_week' => 6,
        'hours_per_day' => 8.0,
        'days_per_month' => 26,
    ]);
});

test('monthly salary remains unchanged for either normal schedule', function () {
    $salary = new EmployeeSalary([
        'basic_salary' => 12000,
        'rate_type' => 'monthly',
    ]);

    expect($salary->basePayForPeriod(22, 5, 8))->toBe(12000.0)
        ->and($salary->basePayForPeriod(26, 6, 8))->toBe(12000.0);
});

test('daily and hourly rates use configured days and hours', function () {
    $dailySalary = new EmployeeSalary([
        'basic_salary' => 500,
        'rate_type' => 'daily',
    ]);
    $hourlySalary = new EmployeeSalary([
        'basic_salary' => 25,
        'rate_type' => 'hourly',
    ]);

    expect($dailySalary->basePayForPeriod(26, 6, 8))->toBe(13000.0)
        ->and($hourlySalary->basePayForPeriod(22, 5, 7.5))->toBe(4125.0)
        ->and($hourlySalary->rateBreakdown(7.5, 5, 22)['monthly_rate'])->toBe(4125.0);
});
