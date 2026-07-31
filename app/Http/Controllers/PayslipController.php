<?php

namespace App\Http\Controllers;

use App\Mail\PayslipMail;
use App\Models\FinancialYear;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\MailConfigService;
use App\Support\PayslipTemplateConfig;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PayslipController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->can('manage-payslips')) {
            $query = Payslip::with(['employee', 'payrollEntry.payrollRun', 'creator'])->where(function ($q) {
                if (Auth::user()->can('manage-any-payslips')) {
                    $q->whereIn('created_by', getCompanyAndUsersId());
                } elseif (Auth::user()->can('manage-own-payslips')) {
                    $q->orWhere('employee_id', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            // Get latest processed payroll run period for default filter
            $latestPayrollRun = PayrollRun::whereIn('created_by', getCompanyAndUsersId())
                ->whereIn('status', ['completed', 'pending_approval', 'final'])
                ->orderBy('pay_date', 'desc')
                ->first();

            // Fallback to current calendar month when no completed run exists
            $defaultPeriod = $latestPayrollRun ? [
                'from' => $latestPayrollRun->pay_period_start,
                'to'   => $latestPayrollRun->pay_period_end,
            ] : [
                'from' => Carbon::now()->startOfMonth()->toDateString(),
                'to'   => Carbon::now()->endOfMonth()->toDateString(),
            ];

            // Handle search
            if ($request->has('search') && !empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('payslip_number', 'like', '%' . $request->search . '%')
                        ->orWhere('employee_name', 'like', '%' . $request->search . '%')
                        ->orWhereHas('employee', function ($subQ) use ($request) {
                            $subQ->where('name', 'like', '%' . $request->search . '%');
                        });
                });
            }

            // Handle employee filter
            if ($request->has('employee_id') && !empty($request->employee_id) && $request->employee_id !== 'all') {
                $query->where('employee_id', $request->employee_id);
            }

            // Handle status filter
            if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Handle date range filter
            // When searching: don't apply default date filter (search across all periods)
            // When not searching: fallback to latest payroll run period
            $isSearching = $request->has('search') && !empty($request->search);

            $dateFrom = !empty($request->date_from) ? $request->date_from : ($isSearching ? null : ($defaultPeriod['from'] ?? null));
            $dateTo   = !empty($request->date_to)   ? $request->date_to   : ($isSearching ? null : ($defaultPeriod['to']   ?? null));

            if ($dateFrom) {
                $query->where('pay_period_start', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('pay_period_end', '<=', $dateTo);
            }

            // Handle sorting
            if ($request->has('sort_field') && !empty($request->sort_field)) {
                $sortField     = $request->sort_field;
                $sortDirection = $request->sort_direction ?? 'asc';

                if (in_array($sortField, ['pay_date', 'created_at'])) {
                    $query->orderBy($sortField, $sortDirection);
                } else {
                    $query->orderBy('pay_date', 'desc');
                }
            } else {
                $query->orderBy('pay_date', 'desc');
            }

            $payslips = $query->paginate($request->per_page ?? 10);

            // Get employees for filter dropdown
            $employees = User::where('type', 'employee')
                ->whereIn('created_by', getCompanyAndUsersId())
                ->get(['id', 'name']);

            return Inertia::render('hr/payslips/index', [
                'payslips'      => $payslips,
                'employees'     => $employees,
                'filters'       => $request->all(['search', 'employee_id', 'status', 'date_from', 'date_to', 'sort_field', 'sort_direction', 'per_page']),
                'defaultPeriod' => $defaultPeriod,
            ]);
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'payroll_entry_ids'   => 'required|array',
            'payroll_entry_ids.*' => 'exists:payroll_entries,id',
        ]);

        // Item 6: do not post payslips into a closed / non-active financial period.
        $runIds = PayrollEntry::whereIn('id', $validated['payroll_entry_ids'])->pluck('payroll_run_id')->unique();
        foreach (PayrollRun::whereIn('id', $runIds)->get() as $r) {
            if ($blk = FinancialYear::payrollBlockReason($r->pay_period_end)) {
                return redirect()->back()->with('error', $blk);
            }
        }

        $generatedCount = 0;
        $errors         = [];
        $generated      = []; // payslips created this request, for employee notifications

        foreach ($validated['payroll_entry_ids'] as $entryId) {
            try {
                $payrollEntry = PayrollEntry::whereIn('created_by', getCompanyAndUsersId())
                    ->find($entryId);

                if (!$payrollEntry) {
                    continue;
                }

                // Generate payslip number first so we can check for duplicates
                $payslipNumber = Payslip::generatePayslipNumber(
                    $payrollEntry->employee_id,
                    $payrollEntry->payrollRun->pay_date
                );

                // Check if payslip already exists by entry ID or payslip number
                $exists = Payslip::where('payroll_entry_id', $entryId)
                    ->orWhere('payslip_number', $payslipNumber)
                    ->exists();

                if ($exists) {
                    continue;
                }

                Payslip::create([
                    'payroll_entry_id' => $entryId,
                    'employee_id'      => $payrollEntry->employee_id,
                    'employee_name'    => $payrollEntry->employee?->name ?? null,
                    'payslip_number'   => $payslipNumber,
                    'pay_period_start' => $payrollEntry->payrollRun->pay_period_start,
                    'pay_period_end'   => $payrollEntry->payrollRun->pay_period_end,
                    'pay_date'         => $payrollEntry->payrollRun->pay_date,
                    'status'           => 'generated',
                    'created_by'       => creatorId(),
                ]);

                $generated[] = ['entry' => $payrollEntry, 'number' => $payslipNumber];
                $generatedCount++;
            } catch (\Exception $e) {
                $errors[] = "Failed to generate payslip for entry ID {$entryId}: " . $e->getMessage();
            }
        }

        // Notify employees their payslip is ready (best-effort — never blocks or
        // fails payslip generation on a mail problem).
        if (!empty($generated)) {
            $this->notifyEmployeesOfPayslips($generated);
        }

        if ($generatedCount > 0) {
            $message = "Generated {$generatedCount} payslip(s) successfully.";
            if (!empty($errors)) {
                $message .= ' Some errors occurred: ' . implode(', ', $errors);
            }
            return redirect()->back()->with('success', __($message));
        } else {
            return redirect()->back()->with('error', __('No payslips were generated. :errors', ['errors' => implode(', ', $errors)]));
        }
    }

    /**
     * Email each employee that their payslip has been generated.
     *
     * Best-effort: guarded by a per-company kill-switch and only attempted when
     * SMTP is configured, so it never blocks or fails the generation request.
     *
     * @param  array<int, array{entry: \App\Models\PayrollEntry, number: string}>  $generated
     */
    private function notifyEmployeesOfPayslips(array $generated): void
    {
        // Kill-switch (defaults on). Set setting 'notify_employee_on_payslip' = '0' to disable.
        if ((string) getSetting('notify_employee_on_payslip', '1') === '0') {
            return;
        }

        // Skip entirely if SMTP isn't configured — avoids a logged failure per employee.
        if (!getSetting('email_host') || !getSetting('email_username') || !getSetting('email_password')) {
            return;
        }

        $service = app(\App\Services\EmailTemplateService::class);

        foreach ($generated as $item) {
            $entry = $item['entry'];
            $user  = $entry->employee; // linked User (type = employee)
            $email = $user?->email;
            if (empty($email)) {
                continue;
            }

            $run    = $entry->payrollRun;
            $period = optional($run?->pay_period_start)->format('d M Y') . ' – ' . optional($run?->pay_period_end)->format('d M Y');

            $variables = [
                '{employee_name}'  => $user->name ?? $entry->employee_name ?? __('Employee'),
                '{payslip_number}' => $item['number'],
                '{pay_period}'     => $period,
                '{pay_date}'       => optional($run?->pay_date)->format('d M Y'),
                '{app_name}'       => config('app.name'),
                '{app_url}'        => config('app.url'),
            ];

            try {
                $service->sendTemplateEmailWithLanguage(
                    templateName: 'Payslip Generated',
                    variables: $variables,
                    toEmail: $email,
                    toName: $user->name,
                    language: $user->lang ?? 'en',
                );
            } catch (\Throwable $e) {
                \Log::warning("Payslip notification failed for {$email}: " . $e->getMessage());
            }
        }
    }

    public function preview(Request $request, $payslipId)
    {
        try {
            $payslip = $this->findPayslip($payslipId);

            if (!$payslip) {
                return response()->json(['error' => __('Payslip not found.')], 404);
            }

            return view('payslips.template', $this->buildPayslipData(
                $payslip,
                $request->boolean('print') ? 'print' : 'preview'
            ));
        } catch (\Throwable $e) {
            return response()->json(['error' => __('Failed to preview payslip: :message', ['message' => $e->getMessage()])], 500);
        }
    }

    public function download($payslipId)
    {
        try {
            $payslip = $this->findPayslip($payslipId);

            if (!$payslip) {
                return response()->json(['error' => __('Payslip not found.')], 404);
            }

            $data = $this->buildPayslipData($payslip, 'pdf');
            $pdf = Pdf::loadView('payslips.template', $data)->setPaper('a4', 'portrait');
            $filename = $this->payslipFilename($payslip);

            $payslip->markAsDownloaded();

            return $pdf->download($filename);
        } catch (\Throwable $e) {
            return response()->json(['error' => __('Failed to download payslip: :message', ['message' => $e->getMessage()])], 500);
        }
    }

    public function emailPayslip($payslipId)
    {
        try {
            $payslip = $this->findPayslip($payslipId);

            if (!$payslip) {
                return response()->json(['success' => false, 'message' => __('Payslip not found.')], 404);
            }

            $employee = $payslip->payrollEntry?->employee;
            if (!$employee || empty($employee->email)) {
                return response()->json([
                    'success' => false,
                    'message' => __('This employee does not have an email address.'),
                ], 422);
            }

            $data = $this->buildPayslipData($payslip, 'pdf');
            $pdfContent = Pdf::loadView('payslips.template', $data)
                ->setPaper('a4', 'portrait')
                ->output();

            $period = $payslip->pay_period_start->format('F Y');
            $filename = $this->payslipFilename($payslip);
            $companyId = getCompanyId(auth()->id()) ?? auth()->id();

            MailConfigService::setDynamicConfig($companyId);
            Mail::to($employee->email, $employee->name)->send(new PayslipMail(
                employeeName: $employee->name ?? $payslip->employee_name ?? __('Employee'),
                period: $period,
                pdfContent: $pdfContent,
                pdfFilename: $filename,
            ));

            $payslip->markAsSent();

            return response()->json([
                'success' => true,
                'message' => __('Payslip emailed successfully to :email.', ['email' => $employee->email]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Payslip email failed', [
                'payslip_id' => $payslipId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to email payslip: :message', ['message' => $e->getMessage()]),
            ], 500);
        }
    }

    private function findPayslip($payslipId): ?Payslip
    {
        return Payslip::with([
            'employee',
            'payrollEntry.payrollRun',
            'payrollEntry.employee.employee.designation',
        ])
            ->where('id', $payslipId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();
    }

    private function buildPayslipData(Payslip $payslip, string $renderMode): array
    {
        $payrollEntry = $payslip->payrollEntry;
        $userModel = $payrollEntry->employee;
        $employeeData = $userModel?->employee;
        $payrollRun = $payrollEntry->payrollRun;
        $companyId = getCompanyId(auth()->id()) ?? auth()->id();
        $companyUser = User::find($companyId);
        $companySettings = array_merge(defaultSettings(), settings($companyId));

        if ($companyUser) {
            $companySettings = array_merge($companySettings, [
                'companyEmail' => $companySettings['companyEmail'] ?? $companyUser->email,
                'companyName' => $companyUser->name,
            ]);
        }

        $leaveBalances = LeaveBalance::with('leaveType:id,name')
            ->where('employee_id', $payrollEntry->employee_id)
            ->where('year', $payrollRun->pay_period_start->format('Y'))
            ->whereIn('created_by', getCompanyAndUsersId())
            ->orderBy('leave_type_id')
            ->get()
            ->map(function (LeaveBalance $balance) {
                $allocated = (float) ($balance->allocated_days ?? 0);
                $used = (float) ($balance->used_days ?? 0);
                $remaining = $balance->remaining_days !== null
                    ? (float) $balance->remaining_days
                    : $allocated - $used;

                return [
                    'name' => $balance->leaveType?->name ?? __('Leave'),
                    'entitled' => $allocated,
                    'taken' => $used,
                    'balance' => $remaining,
                ];
            })
            ->values();

        $employeeFieldValues = [
            'employee_name' => $userModel?->name ?? $payslip->employee_name ?? __('N/A'),
            'nrc' => $employeeData?->nrc ?: __('N/A'),
            'designation' => $employeeData?->designation?->name ?: __('N/A'),
            'pay_period' => $payrollRun->pay_period_start->format('d M Y') . ' - ' . $payrollRun->pay_period_end->format('d M Y'),
            'tpin' => $employeeData?->tpin ?: __('N/A'),
            'napsa_number' => $employeeData?->napsa_number ?: __('N/A'),
            'nhima_number' => $employeeData?->nhima_number ?: __('N/A'),
            'date_of_joining' => $employeeData?->date_of_joining
                ? Carbon::parse($employeeData->date_of_joining)->format('d M Y')
                : __('N/A'),
            'bank_name' => $employeeData?->bank_name ?: __('N/A'),
            'account_number' => $employeeData?->account_number ?: __('N/A'),
        ];

        return [
            'payslip' => $payslip,
            'payrollEntry' => $payrollEntry,
            'employee' => $userModel,
            'payrollRun' => $payrollRun,
            'earnings' => $payrollEntry->earnings_breakdown ?? [],
            'deductions' => $payrollEntry->deductions_breakdown ?? [],
            'employeeData' => $employeeData,
            'employeeFieldValues' => $employeeFieldValues,
            'companySettings' => $companySettings,
            'templateConfig' => PayslipTemplateConfig::forCompany($companyId),
            'leaveBalances' => $leaveBalances,
            'logoDataUri' => $this->imageDataUri($companySettings['logoDark'] ?? $companySettings['logoLight'] ?? null),
            'qrCodeDataUri' => $this->qrCodeDataUri(),
            'renderMode' => $renderMode,
        ];
    }

    private function payslipFilename(Payslip $payslip): string
    {
        $employeeName = Str::slug($payslip->employee?->name ?? $payslip->employee_name ?? 'employee');
        $period = $payslip->pay_period_start->format('M-Y');

        return "payslip-{$employeeName}-{$period}.pdf";
    }

    private function imageDataUri(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'data:image/')) {
            return $path;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $absolutePath = public_path(ltrim($path, '/'));
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mime = mime_content_type($absolutePath) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absolutePath));
    }

    private function qrCodeDataUri(): string
    {
        $svg = \QrCode::format('svg')->size(80)->margin(0)->generate(config('app.url'));

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function bulkGenerate(Request $request)
    {
        $validated = $request->validate([
            'payroll_run_id' => 'required|exists:payroll_runs,id',
        ]);

        // Item 6: do not post payslips into a closed / non-active financial period.
        $run = PayrollRun::find($validated['payroll_run_id']);
        if ($run && ($blk = FinancialYear::payrollBlockReason($run->pay_period_end))) {
            return redirect()->back()->with('error', $blk);
        }

        try {
            $payrollEntries = PayrollEntry::where('payroll_run_id', $validated['payroll_run_id'])
                ->whereIn('created_by', getCompanyAndUsersId())
                ->get();

            $generatedCount = 0;

            foreach ($payrollEntries as $entry) {
                // Generate payslip number first so we can check for duplicates
                $payslipNumber = Payslip::generatePayslipNumber(
                    $entry->employee_id,
                    $entry->payrollRun->pay_date
                );

                // Check if payslip already exists by entry ID or payslip number
                $exists = Payslip::where('payroll_entry_id', $entry->id)
                    ->orWhere('payslip_number', $payslipNumber)
                    ->exists();

                if ($exists) {
                    continue;
                }

                Payslip::create([
                    'payroll_entry_id' => $entry->id,
                    'employee_id'      => $entry->employee_id,
                    'employee_name'    => $entry->employee?->name ?? $entry->employee_name ?? null,
                    'payslip_number'   => $payslipNumber,
                    'pay_period_start' => $entry->payrollRun->pay_period_start,
                    'pay_period_end'   => $entry->payrollRun->pay_period_end,
                    'pay_date'         => $entry->payrollRun->pay_date,
                    'status'           => 'generated',
                    'created_by'       => creatorId(),
                ]);

                $generatedCount++;
            }

            return redirect()->back()->with('success', __('Generated :count payslips successfully.', ['count' => $generatedCount]));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to generate payslips: :message', ['message' => $e->getMessage()]));
        }
    }
}
