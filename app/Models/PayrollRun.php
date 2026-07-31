<?php

namespace App\Models;

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
            $zambiaService = new ZambiaPayrollService($this->created_by);

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

            foreach ($employeeRecords as $employeeRecord) {
                if (!$employeeRecord->user) {
                    continue;
                }
                $this->processEmployeePayroll($employeeRecord->user, $zambiaService, $employeeRecord);
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

        $salaryBreakdown = $employeeSalary->calculateAllComponents();

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
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if (($line['is_earning'] ?? false) && ! ($line['is_taxable'] ?? true)) {
                $nonTaxableEarnings += (float) $line['amount'];
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
            $otherTaxDeductibleDeductions
        );

        // ────────────────────────────────────────────────────────────────────
        // FIX: Build component deductions BEFORE calculating totals so that
        //      additional (non-statutory) deductions are included in net pay.
        // ────────────────────────────────────────────────────────────────────

        // Collect any salary-component deductions that are NOT statutory
        $deductionsFromComponents = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if ($line['is_deduction'] ?? false) {
                $deductionsFromComponents[] = $this->componentBreakdownLine($line);
            }
        }

        // Sum the additional component deductions
        $additionalDeductionsTotal = collect($deductionsFromComponents)->sum('amount');

        // ── FIXED: total deductions = statutory + component deductions ────────
        $totalDeductions = $zambia['total_deductions'] + $additionalDeductionsTotal;

        // ── FIXED: net pay correctly reflects all deductions ──────────────────
        $netPay = $grossPay - $totalDeductions;

        // ── Build earnings breakdown ──────────────────────────────────────────
        $earningsFromComponents = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if ($line['is_earning'] ?? false) {
                $earningsFromComponents[] = $this->componentBreakdownLine($line);
            }
        }

        // Only show NAPSA/NHIMA employer contributions if not exempt
        $employerContributions = [];
        foreach ($salaryBreakdown['component_lines'] ?? [] as $line) {
            if ($line['is_employer_contribution'] ?? false) {
                $employerContributions[] = $this->componentBreakdownLine($line);
            }
        }
        if (!$exemptNapsa) {
            $employerContributions[] = [
                'name'   => 'NAPSA Employer',
                'amount' => $zambia['napsa_employer'],
                'type'   => 'zambia_napsa_employer',
                'is_employer_contribution' => true,
            ];
        }
        if (!$exemptNhima) {
            $employerContributions[] = [
                'name'   => 'NHIMA Employer',
                'amount' => $zambia['nhima_employer'],
                'type'   => 'zambia_nhima_employer',
                'is_employer_contribution' => true,
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
            ];
        }

        $earningsBreakdown = array_merge(
            [['name' => 'Basic Salary', 'amount' => $employeeSalary->basic_salary, 'type' => 'basic_salary']],
            $earningsFromComponents,
            $employerContributions
        );

        // ── Build deductions breakdown ────────────────────────────────────────
        // Statutory deductions (skip if exempt)
        $statutoryDeductions = [];
        if (!$exemptPaye) {
            $statutoryDeductions[] = ['name' => 'PAYE Tax', 'amount' => $zambia['paye'], 'type' => 'zambia_paye'];
        }
        if (!$exemptNapsa) {
            $statutoryDeductions[] = [
                'name'   => 'NAPSA Employee',
                'amount' => $zambia['napsa_employee'],
                'type'   => 'zambia_napsa_employee',
            ];
        }
        if (!$exemptNhima) {
            $statutoryDeductions[] = [
                'name'   => 'NHIMA Employee',
                'amount' => $zambia['nhima_employee'],
                'type'   => 'zambia_nhima_employee',
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

    private function componentBreakdownLine(array $line): array
    {
        return [
            'component_id' => $line['component_id'],
            'name' => $line['name'],
            'amount' => round((float) $line['amount'], 2),
            'type' => $line['type'],
            'is_employer_contribution' => (bool) ($line['is_employer_contribution'] ?? false),
        ];
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
