<?php

namespace App\Models;

use App\Services\TanzaniaPayrollService;
use App\Services\ZambiaPayrollService;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollRun extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'title',
        'payroll_frequency',
        'pay_period_start',
        'pay_period_end',
        'pay_date',
        'total_gross_pay',
        'total_deductions',
        'total_net_pay',
        'employee_count',
        'status',
        'notes',
        'financial_year_id',
        'created_by',
        'unlocked_at',
        'unlocked_by',
        'submitted_for_final_at',
        'submitted_by',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'pay_period_start'       => 'date',
        'pay_period_end'         => 'date',
        'pay_date'               => 'date',
        'total_gross_pay'        => 'decimal:2',
        'total_deductions'       => 'decimal:2',
        'total_net_pay'          => 'decimal:2',
        'unlocked_at'            => 'datetime',
        'submitted_for_final_at' => 'datetime',
        'approved_at'            => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────────────────────────

    public function payrollEntries()
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function payslips()
    {
        return $this->hasManyThrough(Payslip::class, PayrollEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Totals ──────────────────────────────────────────────────────────────

    public function calculateTotals()
    {
        $entries = $this->payrollEntries;

        $this->total_gross_pay  = $entries->sum('gross_pay');
        $this->total_deductions = $entries->sum('total_deductions');
        $this->total_net_pay    = $entries->sum('net_pay');
        $this->employee_count   = $entries->count();

        $this->save();
    }

    public static function resolveWorkSchedule(array $settings): array
    {
        $workingDays = json_decode($settings['working_days'] ?? '[]', true);
        $workingDays = is_array($workingDays) ? $workingDays : [];

        return [
            'working_days' => $workingDays,
            'days_per_week' => max(1, count($workingDays)),
            'hours_per_day' => max(0.01, (float) ($settings['hours_per_day'] ?? 8)),
            'days_per_month' => max(1, (int) ($settings['working_days_per_month'] ?? 22)),
        ];
    }

    // ─── Process Payroll ─────────────────────────────────────────────────────

    public function processPayroll(array $filters = [])
    {
        if (!in_array($this->status, ['draft', 'processing'])) {
            return false;
        }

        $this->status = 'processing';
        $this->save();

        try {
            // Country routing: pick the payroll calculator for this company's
            // configured country_code. Default to Zambia so existing tenants
            // keep their exact behaviour when the column is null.
            $companyRow = User::find($this->created_by);
            $country    = strtoupper((string) ($companyRow->country_code ?? 'ZM'));
            $zambiaService   = $country === 'ZM' ? new ZambiaPayrollService($this->created_by)   : null;
            $tanzaniaService = $country === 'TZ' ? new TanzaniaPayrollService($this->created_by) : null;

            $query = Employee::with('user')
                ->whereIn('created_by', getCompanyAndUsersId())
                ->whereIn('employee_status', ['active', 'probation']);

            if (!empty($filters['branch_id'])) {
                $query->where('branch_id', (int) $filters['branch_id']);
            }
            if (!empty($filters['department_id'])) {
                $query->where('department_id', (int) $filters['department_id']);
            }
            if (!empty($filters['designation_id'])) {
                $query->where('designation_id', (int) $filters['designation_id']);
            }
            // track-a/11: explicit staff_tier filter takes precedence; if the
            // caller didn't pick one, fall back to the implicit permission
            // scope (manage-senior-payroll / manage-junior-payroll).
            if (!empty($filters['staff_tier']) && in_array($filters['staff_tier'], ['senior', 'junior'], true)) {
                $query->where('staff_tier', $filters['staff_tier']);
            } else {
                $query->forCurrentPayrollTiers();
            }

            $employeeRecords = $query->get();

            if ($employeeRecords->isEmpty()) {
                // If this run already has entries (e.g. unlocked run or partial run),
                // just recalculate totals and complete rather than throwing an error.
                if ($this->payrollEntries()->exists()) {
                    $this->calculateTotals();
                    $this->status = 'completed';
                    $this->save();
                    return true;
                }
                throw new \Exception(__('No active employees found for the selected filters.'));
            }

            // When reprocessing after an unlock, delete existing entries so
            // every employee gets freshly recalculated figures.
            // Only do this AFTER confirming there are employees to process.
            if (!is_null($this->unlocked_at)) {
                $this->payrollEntries()->delete();
            }

            // Total head-count for this run — Tanzania SDL uses it for the
            // 10+ employees gate. Computed once and passed into each employee.
            $totalEmployees = $employeeRecords->count();

            foreach ($employeeRecords as $employeeRecord) {
                if (!$employeeRecord->user) {
                    continue;
                }
                if ($country === 'TZ') {
                    $this->processEmployeePayrollTanzania(
                        $employeeRecord->user,
                        $tanzaniaService,
                        $totalEmployees,
                        $employeeRecord
                    );
                } else {
                    $this->processEmployeePayroll($employeeRecord->user, $zambiaService, $employeeRecord);
                }
            }

            $this->calculateTotals();

            // Mark as completed as long as at least one entry was created/exists.
            $this->status = $this->payrollEntries()->exists() ? 'completed' : 'draft';
            $this->save();

            return true;

        } catch (\Exception $e) {
            $this->status = 'draft';
            $this->save();
            throw $e;
        }
    }

    // ─── Process Single Employee ─────────────────────────────────────────────

    private function processEmployeePayroll($employee, ZambiaPayrollService $zambiaService, $employeeRecord = null)
    {
        $existingEntry = PayrollEntry::where('payroll_run_id', $this->id)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($existingEntry) {
            return;
        }

        $companyId          = getCompanyId($this->created_by) ?? $this->created_by;
        $globalSettings     = settings($companyId);
        $workSchedule       = static::resolveWorkSchedule($globalSettings);
        $workingDaysIndices = $workSchedule['working_days'];

        if (empty($workingDaysIndices)) {
            throw new \Exception(__('Please configure working days first.'));
        }

        $employeeSalary = EmployeeSalary::getActiveSalary($employee->id);
        if (!$employeeSalary) {
            return;
        }

        // The configured normal monthly schedule is the payroll denominator.
        // Calendar dates and pay date remain relevant to attendance queries only.
        $totalWorkingDays = $workSchedule['days_per_month'];

        // ── Item 7: interpret the salary by its rate type ────────────────────
        // 'monthly' leaves basic_salary unchanged (existing behaviour, so every
        // current employee is unaffected). Other rate types turn the stored rate
        // into the base pay for THIS period using the days worked. We set it on
        // the in-memory salary object (never saved) so that components (% of
        // basic), the basic line, the NHIMA base and unpaid-leave pro-rating all
        // use the correct period figure.
        $workingDaysPerWeek = $workSchedule['days_per_week'];
        $hoursPerDay        = $workSchedule['hours_per_day'];
        if (($employeeSalary->rate_type ?? 'monthly') !== 'monthly') {
            $employeeSalary->basic_salary = $employeeSalary->basePayForPeriod(
                $totalWorkingDays,
                $workingDaysPerWeek,
                $hoursPerDay
            );
        }

        $salaryBreakdown = $employeeSalary->calculateAllComponents([
            'period_start' => $this->pay_period_start,
            'period_end' => $this->pay_period_end,
            'proration_factor' => $this->componentProrationFactor(
                $employee->id,
                $employeeRecord?->date_of_joining,
                $workingDaysIndices
            ),
        ]);

        $attendanceRecords = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('date', [$this->pay_period_start, $this->pay_period_end])
            ->orderBy('date')
            ->get();

        $presentDays    = $attendanceRecords->whereIn('status', ['present', 'holiday'])->count();
        $halfDays       = $attendanceRecords->where('status', 'half_day')->count();
        $absentDays     = $attendanceRecords->where('status', 'absent')->count();
        $holidayDays    = $attendanceRecords->where('status', 'holiday')->count();
        $overtimeHours  = $attendanceRecords->sum('overtime_hours');
        $overtimeAmount = $attendanceRecords->sum('overtime_amount');

        $leaveData            = $this->getEmployeeLeaveData($employee->id);
        $unpaidLeaveDays      = $leaveData['unpaid_leave_days'] + $absentDays + ($halfDays * 0.5);
        $perDaySalary         = $totalWorkingDays > 0 ? $employeeSalary->basic_salary / $totalWorkingDays : 0;
        $unpaidLeaveDeduction = $perDaySalary * $unpaidLeaveDays;

        $totalEarnings     = $salaryBreakdown['total_earnings'];
        $grossPay          = $totalEarnings - $unpaidLeaveDeduction + $overtimeAmount;
        $componentEarnings = $totalEarnings - $employeeSalary->basic_salary;

        // ── Get exemption flags from employee record ──────────────────────────
        $exemptNapsa = $employeeRecord?->exempt_from_napsa ?? false;
        $exemptNhima = $employeeRecord?->exempt_from_nhima ?? false;
        $exemptPaye  = $employeeRecord?->exempt_from_paye ?? false;
        $exemptSdl   = $employeeRecord?->exempt_from_sdl ?? false;

        $pensionContribution = 0.0;
        $otherTaxDeductibleDeductions = 0.0;
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (! ($line['reduces_taxable_base'] ?? false)) {
                continue;
            }

            if (($line['calculation_type'] ?? null) === 'zambia_pension') {
                $pensionContribution += (float) $line['amount'];
            } else {
                $otherTaxDeductibleDeductions += (float) $line['amount'];
            }
        }

        $nonTaxableEarnings = 0.0;
        $notionalTaxableEarnings = 0.0;
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_earning'] ?? false)
                && ($line['is_cash'] ?? true)
                && ! ($line['is_taxable'] ?? true)) {
                $nonTaxableEarnings += (float) $line['amount'];
            }
            if (($line['is_earning'] ?? false)
                && ($line['is_notional'] ?? false)
                && ($line['is_taxable'] ?? true)
                && ($line['increases_taxable_base'] ?? false)) {
                $notionalTaxableEarnings += (float) $line['amount'];
            }
        }

        // ── Zambia statutory calculations ─────────────────────────────────────
        $zambia = $zambiaService->calculateFullPayroll(
            $grossPay,
            $employeeSalary->basic_salary,
            $exemptNapsa,
            $exemptNhima,
            $pensionContribution,
            $exemptPaye,
            $nonTaxableEarnings,
            $exemptSdl,
            $otherTaxDeductibleDeductions,
            $notionalTaxableEarnings
        );

        // ────────────────────────────────────────────────────────────────────
        // FIX: Build component deductions BEFORE calculating totals so that
        //      additional (non-statutory) deductions are included in net pay.
        // ────────────────────────────────────────────────────────────────────

        // Collect any salary-component deductions that are NOT statutory
        $componentDeductionLines = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if ($line['is_deduction'] ?? false) {
                $componentDeductionLines[] = $line;
            }
        }

        // Optional deductions are limited to the employee's remaining cash.
        // Compulsory deductions are always applied in full and may make net pay
        // negative. Recalculate PAYE when a pre-tax deduction is reduced.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $componentDeductionLines = $this->applyComponentDeductionLimits(
                $salaryBreakdown['component_lines'] ?? [],
                max(0.0, $grossPay - $zambia['total_deductions'])
            );

            [$pensionContribution, $otherTaxDeductibleDeductions] = $this->taxReliefFromComponentLines($componentDeductionLines);
            $zambia = $zambiaService->calculateFullPayroll(
                $grossPay,
                $employeeSalary->basic_salary,
                $exemptNapsa,
                $exemptNhima,
                $pensionContribution,
                $exemptPaye,
                $nonTaxableEarnings,
                $exemptSdl,
                $otherTaxDeductibleDeductions,
                $notionalTaxableEarnings
            );
        }

        $deductionsFromComponents = [];
        foreach ($componentDeductionLines as $line) {
            if ($line['affect_payslip'] ?? true) {
                $deductionsFromComponents[] = $this->componentBreakdownLine($line);
            }
        }

        // Sum the additional component deductions
        $additionalDeductionsTotal = collect($componentDeductionLines)->sum('amount');

        // ── FIXED: total deductions = statutory + component deductions ────────
        $totalDeductions = $zambia['total_deductions'] + $additionalDeductionsTotal;

        // ── FIXED: net pay correctly reflects all deductions ──────────────────
        $netPay = $grossPay - $totalDeductions;

        // ── Build earnings breakdown ──────────────────────────────────────────
        $earningsFromComponents = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_earning'] ?? false) && ($line['affect_payslip'] ?? true)) {
                $earningsFromComponents[] = $this->componentBreakdownLine($line);
            }
        }

        // Only show NAPSA/NHIMA employer contributions if not exempt
        $employerContributions = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_employer_contribution'] ?? false) && ($line['affect_payslip'] ?? true)) {
                $employerContributions[] = $this->componentBreakdownLine($line);
            }
        }
        if (!$exemptNapsa) {
            $employerContributions[] = [
                'name'   => 'NAPSA Employer',
                'amount' => $zambia['napsa_employer'],
                'type'   => 'zambia_napsa_employer',
                'is_employer_contribution' => true,
                'print' => true,
            ];
        }
        if (!$exemptNhima) {
            $employerContributions[] = [
                'name'   => 'NHIMA Employer',
                'amount' => $zambia['nhima_employer'],
                'type'   => 'zambia_nhima_employer',
                'is_employer_contribution' => true,
                'print' => true,
            ];
        }
        // SDL: employer levy (0.5% of this employee's gross), shown as an
        // employer contribution and picked up by payslips + Zambia reports.
        if (!$exemptSdl && ($zambia['sdl'] ?? 0) > 0) {
            $employerContributions[] = [
                'name'   => 'SDL (Employer)',
                'amount' => $zambia['sdl'],
                'type'   => 'zambia_sdl',
                'is_employer_contribution' => true,
                'print' => true,
            ];
        }

        $earningsBreakdown = array_merge(
            [['name' => 'Basic Salary', 'amount' => $employeeSalary->basic_salary, 'type' => 'basic_salary', 'print' => true]],
            $earningsFromComponents,
            $employerContributions
        );

        // ── Build deductions breakdown ────────────────────────────────────────
        // Statutory deductions (skip if exempt)
        $statutoryDeductions = [];
        if (!$exemptPaye) {
            $statutoryDeductions[] = ['name' => 'PAYE Tax', 'amount' => $zambia['paye'], 'type' => 'zambia_paye', 'print' => true];
        }
        if (!$exemptNapsa) {
            $statutoryDeductions[] = [
                'name'   => 'NAPSA Employee',
                'amount' => $zambia['napsa_employee'],
                'type'   => 'zambia_napsa_employee',
                'print'  => true,
            ];
        }
        if (!$exemptNhima) {
            $statutoryDeductions[] = [
                'name'   => 'NHIMA Employee',
                'amount' => $zambia['nhima_employee'],
                'type'   => 'zambia_nhima_employee',
                'print'  => true,
            ];
        }

        // Component deductions come first, then statutory — so the payslip reads
        // "additional deductions → then statutory deductions"
        $deductionsBreakdown = array_merge($deductionsFromComponents, $statutoryDeductions);

        PayrollEntry::create([
            'payroll_run_id'         => $this->id,
            'employee_id'            => $employee->id,
            'employee_name'          => $employee->name,
            'basic_salary'           => $employeeSalary->basic_salary,
            'component_earnings'     => $componentEarnings,
            'total_earnings'         => $totalEarnings,
            'total_deductions'       => $totalDeductions,   // ← now includes component deductions
            'gross_pay'              => $grossPay,
            'net_pay'                => $netPay,            // ← now correctly reduced
            'working_days'           => $totalWorkingDays,
            'present_days'           => $presentDays,
            'half_days'              => $halfDays,
            'holiday_days'           => $holidayDays,
            'paid_leave_days'        => $leaveData['paid_leave_days'],
            'unpaid_leave_days'      => $unpaidLeaveDays,
            'absent_days'            => $absentDays,
            'overtime_hours'         => $overtimeHours,
            'overtime_amount'        => $overtimeAmount,
            'per_day_salary'         => $perDaySalary,
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'earnings_breakdown'     => $earningsBreakdown,
            'deductions_breakdown'   => $deductionsBreakdown,
            'created_by'             => $this->created_by,
        ]);
    }

    // ─── Process Single Employee (Tanzania) ──────────────────────────────────
    //
    // Country-routed variant of processEmployeePayroll. Shares the same
    // salary/leave/attendance derivation as the Zambia path but swaps the
    // statutory calculator for TanzaniaPayrollService and labels the
    // deduction lines with the Tanzania statutory names (PAYE / NSSF / SDL
    // / WCF). Head-count is passed in so SDL's 10-employee gate is evaluated
    // consistently for the whole run.
    private function processEmployeePayrollTanzania(
        $employee,
        TanzaniaPayrollService $tanzaniaService,
        int $employeeCount,
        $employeeRecord = null
    ) {
        $existingEntry = PayrollEntry::where('payroll_run_id', $this->id)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($existingEntry) {
            return;
        }

        $companyId          = getCompanyId($this->created_by) ?? $this->created_by;
        $globalSettings     = settings($companyId);
        $workSchedule       = static::resolveWorkSchedule($globalSettings);
        $workingDaysIndices = $workSchedule['working_days'];

        if (empty($workingDaysIndices)) {
            throw new \Exception(__('Please configure working days first.'));
        }

        $employeeSalary = EmployeeSalary::getActiveSalary($employee->id);
        if (!$employeeSalary) {
            return;
        }

        $totalWorkingDays = $workSchedule['days_per_month'];
        $workingDaysPerWeek = $workSchedule['days_per_week'];
        $hoursPerDay        = $workSchedule['hours_per_day'];
        if (($employeeSalary->rate_type ?? 'monthly') !== 'monthly') {
            $employeeSalary->basic_salary = $employeeSalary->basePayForPeriod(
                $totalWorkingDays,
                $workingDaysPerWeek,
                $hoursPerDay
            );
        }

        $salaryBreakdown = $employeeSalary->calculateAllComponents([
            'period_start'     => $this->pay_period_start,
            'period_end'       => $this->pay_period_end,
            'proration_factor' => $this->componentProrationFactor(
                $employee->id,
                $employeeRecord?->date_of_joining,
                $workingDaysIndices
            ),
        ]);

        $attendanceRecords = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('date', [$this->pay_period_start, $this->pay_period_end])
            ->orderBy('date')
            ->get();

        $presentDays    = $attendanceRecords->whereIn('status', ['present', 'holiday'])->count();
        $halfDays       = $attendanceRecords->where('status', 'half_day')->count();
        $absentDays     = $attendanceRecords->where('status', 'absent')->count();
        $holidayDays    = $attendanceRecords->where('status', 'holiday')->count();
        $overtimeHours  = $attendanceRecords->sum('overtime_hours');
        $overtimeAmount = $attendanceRecords->sum('overtime_amount');

        $leaveData            = $this->getEmployeeLeaveData($employee->id);
        $unpaidLeaveDays      = $leaveData['unpaid_leave_days'] + $absentDays + ($halfDays * 0.5);
        $perDaySalary         = $totalWorkingDays > 0 ? $employeeSalary->basic_salary / $totalWorkingDays : 0;
        $unpaidLeaveDeduction = $perDaySalary * $unpaidLeaveDays;

        $totalEarnings     = $salaryBreakdown['total_earnings'];
        $grossPay          = $totalEarnings - $unpaidLeaveDeduction + $overtimeAmount;
        $componentEarnings = $totalEarnings - $employeeSalary->basic_salary;

        // Tanzania re-uses the same exemption flags stored on the employee
        // record. The columns were named for Zambia (exempt_from_napsa /
        // exempt_from_nhima) — we re-use `exempt_from_napsa` as the NSSF
        // exemption on the Tanzania side, since employers rarely need two
        // separate exemption columns and NSSF is the direct analogue of
        // NAPSA. exempt_from_paye + exempt_from_sdl re-use verbatim. WCF
        // shares the SDL exemption because the two are always exempted
        // together in Tanzania practice.
        $exemptNssf = $employeeRecord?->exempt_from_napsa ?? false;
        $exemptPaye = $employeeRecord?->exempt_from_paye  ?? false;
        $exemptSdl  = $employeeRecord?->exempt_from_sdl   ?? false;
        $exemptWcf  = $employeeRecord?->exempt_from_sdl   ?? false;

        $otherTaxDeductibleDeductions = 0.0;
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if ($line['reduces_taxable_base'] ?? false) {
                $otherTaxDeductibleDeductions += (float) $line['amount'];
            }
        }

        $nonTaxableEarnings = 0.0;
        $notionalTaxableEarnings = 0.0;
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_earning'] ?? false)
                && ($line['is_cash'] ?? true)
                && ! ($line['is_taxable'] ?? true)) {
                $nonTaxableEarnings += (float) $line['amount'];
            }
            if (($line['is_earning'] ?? false)
                && ($line['is_notional'] ?? false)
                && ($line['is_taxable'] ?? true)
                && ($line['increases_taxable_base'] ?? false)) {
                $notionalTaxableEarnings += (float) $line['amount'];
            }
        }

        $tanzania = $tanzaniaService->calculateFullPayroll(
            $grossPay,
            $employeeCount,
            $exemptNssf,
            $exemptPaye,
            $exemptSdl,
            $exemptWcf,
            $nonTaxableEarnings,
            $otherTaxDeductibleDeductions,
            $notionalTaxableEarnings
        );

        // Component deductions (matches Zambia flow — recompute PAYE if the
        // employee's remaining cash forces optional deductions to be trimmed).
        $componentDeductionLines = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if ($line['is_deduction'] ?? false) {
                $componentDeductionLines[] = $line;
            }
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $componentDeductionLines = $this->applyComponentDeductionLimits(
                $salaryBreakdown['component_lines'] ?? [],
                max(0.0, $grossPay - $tanzania['total_deductions'])
            );

            $otherTaxDeductibleDeductions = 0.0;
            foreach ($componentDeductionLines as $line) {
                if ($line['reduces_taxable_base'] ?? false) {
                    $otherTaxDeductibleDeductions += (float) $line['amount'];
                }
            }

            $tanzania = $tanzaniaService->calculateFullPayroll(
                $grossPay,
                $employeeCount,
                $exemptNssf,
                $exemptPaye,
                $exemptSdl,
                $exemptWcf,
                $nonTaxableEarnings,
                $otherTaxDeductibleDeductions,
                $notionalTaxableEarnings
            );
        }

        $deductionsFromComponents = [];
        foreach ($componentDeductionLines as $line) {
            if ($line['affect_payslip'] ?? true) {
                $deductionsFromComponents[] = $this->componentBreakdownLine($line);
            }
        }
        $additionalDeductionsTotal = collect($componentDeductionLines)->sum('amount');
        $totalDeductions = $tanzania['total_deductions'] + $additionalDeductionsTotal;
        $netPay = $grossPay - $totalDeductions;

        // Earnings breakdown
        $earningsFromComponents = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_earning'] ?? false) && ($line['affect_payslip'] ?? true)) {
                $earningsFromComponents[] = $this->componentBreakdownLine($line);
            }
        }

        $employerContributions = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_employer_contribution'] ?? false) && ($line['affect_payslip'] ?? true)) {
                $employerContributions[] = $this->componentBreakdownLine($line);
            }
        }
        if (!$exemptNssf) {
            $employerContributions[] = [
                'name'   => 'NSSF Employer',
                'amount' => $tanzania['nssf_employer'],
                'type'   => 'tanzania_nssf_employer',
                'is_employer_contribution' => true,
                'print' => true,
            ];
        }
        if (!$exemptSdl && ($tanzania['sdl'] ?? 0) > 0) {
            $employerContributions[] = [
                'name'   => 'SDL (Employer)',
                'amount' => $tanzania['sdl'],
                'type'   => 'tanzania_sdl',
                'is_employer_contribution' => true,
                'print' => true,
            ];
        }
        if (!$exemptWcf && ($tanzania['wcf'] ?? 0) > 0) {
            $employerContributions[] = [
                'name'   => 'WCF (Employer)',
                'amount' => $tanzania['wcf'],
                'type'   => 'tanzania_wcf',
                'is_employer_contribution' => true,
                'print' => true,
            ];
        }

        $earningsBreakdown = array_merge(
            [['name' => 'Basic Salary', 'amount' => $employeeSalary->basic_salary, 'type' => 'basic_salary', 'print' => true]],
            $earningsFromComponents,
            $employerContributions
        );

        $statutoryDeductions = [];
        if (!$exemptPaye) {
            $statutoryDeductions[] = ['name' => 'PAYE Tax', 'amount' => $tanzania['paye'], 'type' => 'tanzania_paye', 'print' => true];
        }
        if (!$exemptNssf) {
            $statutoryDeductions[] = [
                'name'   => 'NSSF Employee',
                'amount' => $tanzania['nssf_employee'],
                'type'   => 'tanzania_nssf_employee',
                'print'  => true,
            ];
        }

        $deductionsBreakdown = array_merge($deductionsFromComponents, $statutoryDeductions);

        PayrollEntry::create([
            'payroll_run_id'         => $this->id,
            'employee_id'            => $employee->id,
            'employee_name'          => $employee->name,
            'basic_salary'           => $employeeSalary->basic_salary,
            'component_earnings'     => $componentEarnings,
            'total_earnings'         => $totalEarnings,
            'total_deductions'       => $totalDeductions,
            'gross_pay'              => $grossPay,
            'net_pay'                => $netPay,
            'working_days'           => $totalWorkingDays,
            'present_days'           => $presentDays,
            'half_days'              => $halfDays,
            'holiday_days'           => $holidayDays,
            'paid_leave_days'        => $leaveData['paid_leave_days'],
            'unpaid_leave_days'      => $unpaidLeaveDays,
            'absent_days'            => $absentDays,
            'overtime_hours'         => $overtimeHours,
            'overtime_amount'        => $overtimeAmount,
            'per_day_salary'         => $perDaySalary,
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'earnings_breakdown'     => $earningsBreakdown,
            'deductions_breakdown'   => $deductionsBreakdown,
            'created_by'             => $this->created_by,
        ]);
    }

    private function componentBreakdownLine(array $line): array
    {
        return [
            'component_id' => $line['component_id'],
            'name' => $line['name'],
            'amount' => round((float) $line['amount'], 2),
            'type' => $line['type'],
            'is_employer_contribution' => (bool) ($line['is_employer_contribution'] ?? false),
            'print' => (bool) ($line['print_on_payslip'] ?? true),
            'is_notional' => (bool) ($line['is_notional'] ?? false),
            'compulsory' => (bool) ($line['compulsory_deduction'] ?? false),
            'requested_amount' => round((float) ($line['requested_amount'] ?? $line['amount']), 2),
        ];
    }

    private function applyComponentDeductionLimits(array $lines, float $availableCash): array
    {
        $applied = [];

        foreach ($lines as $line) {
            if (! ($line['is_deduction'] ?? false)) {
                continue;
            }

            $requested = round(max(0.0, (float) ($line['requested_amount'] ?? $line['amount'] ?? 0)), 2);
            $amount = ($line['compulsory_deduction'] ?? false)
                ? $requested
                : min($requested, max(0.0, $availableCash));

            $line['requested_amount'] = $requested;
            $line['amount'] = round($amount, 2);
            $availableCash -= $amount;
            $applied[] = $line;
        }

        return $applied;
    }

    private function taxReliefFromComponentLines(array $lines): array
    {
        $pension = 0.0;
        $other = 0.0;

        foreach ($lines as $line) {
            if (! ($line['reduces_taxable_base'] ?? false)) {
                continue;
            }
            if (($line['calculation_type'] ?? null) === 'zambia_pension') {
                $pension += (float) $line['amount'];
            } else {
                $other += (float) $line['amount'];
            }
        }

        return [$pension, $other];
    }

    private function componentProrationFactor(int $employeeId, $joinedOn, array $workingDays): float
    {
        $periodStart = $this->pay_period_start->copy()->startOfDay();
        $periodEnd = $this->pay_period_end->copy()->startOfDay();
        $employmentStart = $joinedOn ? \Carbon\Carbon::parse($joinedOn)->startOfDay() : $periodStart->copy();

        $terminationDate = Termination::where('employee_id', $employeeId)
            ->where('status', 'completed')
            ->whereIn('created_by', getCompanyAndUsersId())
            ->whereDate('termination_date', '<=', $periodEnd)
            ->max('termination_date');
        $resignationDate = Resignation::where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'completed'])
            ->whereIn('created_by', getCompanyAndUsersId())
            ->whereDate('last_working_day', '<=', $periodEnd)
            ->max('last_working_day');

        $employmentEnd = collect([$terminationDate, $resignationDate])
            ->filter()
            ->map(fn ($date) => \Carbon\Carbon::parse($date)->startOfDay())
            ->sortBy(fn ($date) => $date->timestamp)
            ->first() ?? $periodEnd->copy();

        $eligibleStart = $employmentStart->greaterThan($periodStart) ? $employmentStart : $periodStart;
        $eligibleEnd = $employmentEnd->lessThan($periodEnd) ? $employmentEnd : $periodEnd;
        $scheduled = $this->countScheduledDays($periodStart, $periodEnd, $workingDays);

        if ($eligibleStart->greaterThan($eligibleEnd) || $scheduled === 0) {
            return 0.0;
        }

        return min(1.0, $this->countScheduledDays($eligibleStart, $eligibleEnd, $workingDays) / $scheduled);
    }

    private function countScheduledDays($start, $end, array $workingDays): int
    {
        $count = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (in_array((int) $date->format('w'), $workingDays, true)) {
                $count++;
            }
        }

        return $count;
    }

    // ─── Leave Data ──────────────────────────────────────────────────────────

    private function getEmployeeLeaveData($employeeId)
    {
        $leaveApplications = \App\Models\LeaveApplication::where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->where(function ($query) {
                $query->whereBetween('start_date', [$this->pay_period_start, $this->pay_period_end])
                    ->orWhereBetween('end_date', [$this->pay_period_start, $this->pay_period_end])
                    ->orWhere(function ($q) {
                        $q->where('start_date', '<=', $this->pay_period_start)
                            ->where('end_date', '>=', $this->pay_period_end);
                    });
            })
            ->with('leaveType')
            ->get();

        $paidLeaveDays   = 0;
        $unpaidLeaveDays = 0;

        foreach ($leaveApplications as $leave) {
            $leaveStart = max($leave->start_date, $this->pay_period_start);
            $leaveEnd   = min($leave->end_date, $this->pay_period_end);
            $leaveDays  = $leaveStart->diffInDays($leaveEnd) + 1;

            if ($leave->leaveType->is_paid) {
                $paidLeaveDays += $leaveDays;
            } else {
                $unpaidLeaveDays += $leaveDays;
            }
        }

        return [
            'paid_leave_days'   => $paidLeaveDays,
            'unpaid_leave_days' => $unpaidLeaveDays,
        ];
    }
}
