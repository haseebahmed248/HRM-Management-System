<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanRepayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Salary advances and staff loans. Recovery happens inside payroll processing
 * (PayrollRun::loanRecoveryLines); this controller manages the loans, early
 * settlements and the outstanding balances report.
 */
class EmployeeLoanController extends Controller
{
    public function index(Request $request)
    {
        if (!Auth::user()->can('manage-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $query = $this->scopedQuery()
            ->withRepaid()
            ->with([
                'employee:id,name',
                'employee.employee:id,user_id,employee_id',
                'repayments' => fn ($q) => $q->orderBy('paid_on')->orderBy('id'),
                'repayments.payrollRun:id,title',
            ]);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', $search)
                    ->orWhere('description', 'like', $search)
                    ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', $search));
            });
        }

        if ($request->filled('loan_type') && $request->loan_type !== 'all') {
            $query->where('loan_type', $request->loan_type);
        }

        // "completed" is derived from the balance, so filter it in SQL the same way.
        $repaidSql = '(SELECT COALESCE(SUM(r.amount), 0) FROM employee_loan_repayments r WHERE r.employee_loan_id = employee_loans.id)';
        $status = $request->get('status', 'all');
        if ($status === 'completed') {
            $query->where('status', '!=', 'cancelled')->whereRaw("employee_loans.amount - {$repaidSql} <= 0");
        } elseif (in_array($status, ['active', 'paused'], true)) {
            $query->where('status', $status)->whereRaw("employee_loans.amount - {$repaidSql} > 0");
        } elseif ($status === 'cancelled') {
            $query->where('status', 'cancelled');
        }

        $sortField = in_array($request->get('sort_field'), ['reference', 'amount', 'start_date', 'created_at'], true)
            ? $request->get('sort_field')
            : 'created_at';
        $sortDirection = $request->get('sort_direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        $loans = $query->paginate($request->per_page ?? 10)->withQueryString();
        $loans->getCollection()->transform(fn (EmployeeLoan $loan) => $this->present($loan));

        return Inertia::render('hr/employee-loans/index', [
            'loans'     => $loans,
            'summary'   => $this->summary(),
            'employees' => $this->employeeOptions(),
            'loanTypes' => EmployeeLoan::TYPES,
            'filters'   => $request->all(['search', 'status', 'loan_type', 'sort_field', 'sort_direction', 'per_page']),
        ]);
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('create-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $validated = $this->validateLoan($request);

        if (!$this->employeeInCompany((int) $validated['employee_id'])) {
            return redirect()->back()->with('error', __('Employee not found.'));
        }

        DB::transaction(function () use ($validated) {
            EmployeeLoan::create([
                ...$validated,
                'reference'          => EmployeeLoan::nextReference($validated['loan_type'], getCompanyAndUsersId()),
                'installment_amount' => round($validated['amount'] / $validated['installments'], 2),
                'status'             => 'active',
                'created_by'         => creatorId(),
            ]);
        });

        return redirect()->back()->with('success', __('Loan / advance created successfully'));
    }

    public function update(Request $request, $loanId)
    {
        if (!Auth::user()->can('edit-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loan = $this->findLoan($loanId);
        if (!$loan) {
            return redirect()->back()->with('error', __('Loan not found.'));
        }

        $validated = $this->validateLoan($request);
        $repaid = $loan->repaidAmount();

        if ($repaid > 0 && (int) $validated['employee_id'] !== (int) $loan->employee_id) {
            return redirect()->back()->with('error', __('The employee cannot be changed after deductions have started.'));
        }
        if (!$this->employeeInCompany((int) $validated['employee_id'])) {
            return redirect()->back()->with('error', __('Employee not found.'));
        }
        if ($validated['amount'] < $repaid) {
            return redirect()->back()->with('error', __('Amount cannot be less than the :repaid already recovered.', ['repaid' => number_format($repaid, 2)]));
        }

        // A type change would leave the old ADV-/LN- prefix, so keep the type once created.
        unset($validated['loan_type']);
        $loan->update([
            ...$validated,
            'installment_amount' => round($validated['amount'] / $validated['installments'], 2),
        ]);

        return redirect()->back()->with('success', __('Loan / advance updated successfully'));
    }

    public function destroy($loanId)
    {
        if (!Auth::user()->can('delete-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loan = $this->findLoan($loanId);
        if (!$loan) {
            return redirect()->back()->with('error', __('Loan not found.'));
        }
        if ($loan->repayments()->exists()) {
            return redirect()->back()->with('error', __('This loan already has repayments. Cancel it instead of deleting it.'));
        }

        $loan->delete();

        return redirect()->back()->with('success', __('Loan / advance deleted successfully'));
    }

    public function togglePause($loanId)
    {
        if (!Auth::user()->can('edit-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loan = $this->findLoan($loanId);
        if (!$loan || in_array($loan->displayStatus(), ['completed', 'cancelled'], true)) {
            return redirect()->back()->with('error', __('Only an open loan can be paused or resumed.'));
        }

        $loan->update(['status' => $loan->status === 'paused' ? 'active' : 'paused']);

        return redirect()->back()->with('success', $loan->status === 'paused'
            ? __('Deductions paused. Payroll will skip this loan until it is resumed.')
            : __('Deductions resumed from the next payroll run.'));
    }

    public function cancel($loanId)
    {
        if (!Auth::user()->can('edit-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loan = $this->findLoan($loanId);
        if (!$loan || $loan->displayStatus() === 'completed') {
            return redirect()->back()->with('error', __('Loan not found or already fully recovered.'));
        }

        $loan->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', __('Loan cancelled. No further deductions will be made.'));
    }

    /** Early settlement or any repayment made outside payroll (cash, bank transfer). */
    public function storeRepayment(Request $request, $loanId)
    {
        if (!Auth::user()->can('edit-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loan = $this->findLoan($loanId);
        if (!$loan || $loan->status === 'cancelled') {
            return redirect()->back()->with('error', __('Loan not found.'));
        }

        $balance = $loan->balance();
        $validated = $request->validate([
            'amount'  => 'required|numeric|min:0.01|max:' . $balance,
            'paid_on' => 'required|date',
            'notes'   => 'nullable|string|max:255',
        ], [
            'amount.max' => __('Amount cannot be more than the outstanding balance of :balance.', ['balance' => number_format($balance, 2)]),
        ]);

        EmployeeLoanRepayment::create([
            'employee_loan_id' => $loan->id,
            'amount'           => round((float) $validated['amount'], 2),
            'paid_on'          => $validated['paid_on'],
            'method'           => 'manual',
            'notes'            => $validated['notes'] ?? null,
            'created_by'       => creatorId(),
        ]);

        return redirect()->back()->with('success', __('Repayment recorded.'));
    }

    public function destroyRepayment($loanId, $repaymentId)
    {
        if (!Auth::user()->can('edit-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loan = $this->findLoan($loanId);
        $repayment = $loan?->repayments()->where('id', $repaymentId)->first();
        if (!$repayment) {
            return redirect()->back()->with('error', __('Repayment not found.'));
        }
        // Payroll deductions belong to their payroll run; unlock and reprocess the run instead.
        if ($repayment->method !== 'manual') {
            return redirect()->back()->with('error', __('Payroll deductions can only be reversed by unlocking and reprocessing that payroll run.'));
        }

        $repayment->delete();

        return redirect()->back()->with('success', __('Repayment removed.'));
    }

    /** Outstanding balances report (CSV / Excel / PDF), in the standard report frame. */
    public function export(Request $request)
    {
        if (!Auth::user()->can('manage-employee-loans')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $loans = $this->scopedQuery()
            ->withRepaid()
            ->with(['employee:id,name', 'employee.employee:id,user_id,employee_id'])
            ->where('status', '!=', 'cancelled')
            ->orderBy('employee_id')
            ->orderBy('start_date')
            ->get()
            ->map(fn (EmployeeLoan $loan) => $this->present($loan));

        if ($request->get('scope', 'outstanding') === 'outstanding') {
            $loans = $loans->filter(fn ($l) => $l['balance'] > 0)->values();
        }

        $fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
        $rows = $loans->map(fn ($l) => [
            $l['reference'],
            $l['employee_name'],
            $l['employee_code'],
            $l['type_label'],
            Carbon::parse($l['disbursed_on'])->format('d M Y'),
            Carbon::parse($l['start_date'])->format('M Y'),
            $fmt($l['amount']),
            $fmt($l['installment_amount']),
            $fmt($l['repaid']),
            $fmt($l['balance']),
            $l['installments_left'],
            ucfirst($l['display_status']),
        ])->all();

        $rows[] = ['', 'TOTALS', '', '', '', '', $fmt($loans->sum('amount')), '', $fmt($loans->sum('repaid')), $fmt($loans->sum('balance')), '', ''];

        $sections = [
            ['type' => 'title', 'content' => 'Loans & Advances Balances'],
            ['type' => 'info', 'rows' => [
                ['As At', now()->format('d M Y')],
                ['Loans Listed', $loans->count()],
                ['Total Outstanding', $fmt($loans->sum('balance'))],
            ]],
            ['type' => 'blank'],
            ['type' => 'table', 'title' => 'Loans & Advances', 'headers' => [
                'Reference', 'Employee', 'Employee ID', 'Type', 'Disbursed', 'First Deduction',
                'Amount', 'Monthly Installment', 'Recovered', 'Balance', 'Installments Left', 'Status',
            ], 'rows' => $rows],
        ];

        $format = in_array($request->get('format'), ['csv', 'excel', 'pdf'], true) ? $request->get('format') : 'csv';

        return app(ZambiaReportController::class)->exportSections($format, 'Loans_Advances_' . now()->format('Y_m_d'), $sections);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function scopedQuery()
    {
        return EmployeeLoan::query()->where(function ($q) {
            if (Auth::user()->can('manage-any-employee-loans')) {
                $q->whereIn('created_by', getCompanyAndUsersId());
            } elseif (Auth::user()->can('manage-own-employee-loans')) {
                $q->where('created_by', Auth::id());
            } else {
                $q->whereRaw('1 = 0');
            }
        });
    }

    private function findLoan($loanId): ?EmployeeLoan
    {
        return $this->scopedQuery()->withRepaid()->where('id', $loanId)->first();
    }

    private function validateLoan(Request $request): array
    {
        $validated = $request->validate([
            'employee_id'  => 'required|integer|exists:users,id',
            'loan_type'    => 'required|in:' . implode(',', array_keys(EmployeeLoan::TYPES)),
            'amount'       => 'required|numeric|min:1',
            'installments' => 'required|integer|min:1|max:120',
            'disbursed_on' => 'required|date',
            'start_month'  => 'required|date_format:Y-m',
            'description'  => 'nullable|string|max:255',
            'notes'        => 'nullable|string|max:2000',
        ]);

        // Deductions are monthly; store the first day of the chosen month.
        $validated['start_date'] = Carbon::createFromFormat('Y-m', $validated['start_month'])->startOfMonth()->toDateString();
        unset($validated['start_month']);
        $validated['amount'] = round((float) $validated['amount'], 2);

        return $validated;
    }

    private function employeeInCompany(int $userId): bool
    {
        return Employee::where('user_id', $userId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->exists();
    }

    private function employeeOptions()
    {
        $employeeUserIds = Employee::whereIn('created_by', getCompanyAndUsersId())->pluck('user_id');

        return User::with('employee:id,user_id,employee_id')
            ->whereIn('id', $employeeUserIds)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($user) => [
                'id'          => $user->id,
                'name'        => $user->name,
                'employee_id' => $user->employee->employee_id ?? '',
            ]);
    }

    private function present(EmployeeLoan $loan): array
    {
        $repaid = $loan->repaidAmount();
        $balance = $loan->balance();
        $installment = (float) $loan->installment_amount;

        return [
            'id'                 => $loan->id,
            'reference'          => $loan->reference,
            'employee_id'        => $loan->employee_id,
            'employee_name'      => $loan->employee?->name ?? '',
            'employee_code'      => $loan->employee?->employee?->employee_id ?? '',
            'loan_type'          => $loan->loan_type,
            'type_label'         => $loan->typeLabel(),
            'description'        => $loan->description,
            'notes'              => $loan->notes,
            'amount'             => round((float) $loan->amount, 2),
            'installments'       => $loan->installments,
            'installment_amount' => round($installment, 2),
            'disbursed_on'       => $loan->disbursed_on?->toDateString(),
            'start_date'         => $loan->start_date?->toDateString(),
            'start_month'        => $loan->start_date?->format('Y-m'),
            'status'             => $loan->status,
            'display_status'     => $loan->displayStatus(),
            'repaid'             => $repaid,
            'balance'            => $balance,
            'installments_left'  => $balance <= 0 ? 0 : ($installment > 0 ? (int) ceil(round($balance / $installment, 4)) : 1),
            'has_repayments'     => $repaid > 0,
            'repayments'         => $loan->relationLoaded('repayments')
                ? $loan->repayments->map(fn (EmployeeLoanRepayment $r) => [
                    'id'      => $r->id,
                    'amount'  => round((float) $r->amount, 2),
                    'paid_on' => $r->paid_on?->toDateString(),
                    'method'  => $r->method,
                    'source'  => $r->method === 'payroll' ? ($r->payrollRun?->title ?? __('Payroll')) : __('Manual repayment'),
                    'notes'   => $r->notes,
                ])->values()
                : [],
        ];
    }

    private function summary(): array
    {
        $loans = $this->scopedQuery()->withRepaid()->where('status', '!=', 'cancelled')->get();
        $open = $loans->filter(fn (EmployeeLoan $l) => $l->balance() > 0);

        return [
            'open_count'  => $open->count(),
            'outstanding' => round($open->sum(fn (EmployeeLoan $l) => $l->balance()), 2),
            'disbursed'   => round((float) $loans->sum('amount'), 2),
            'recovered'   => round($loans->sum(fn (EmployeeLoan $l) => $l->repaidAmount()), 2),
            'paused'      => $open->where('status', 'paused')->count(),
        ];
    }
}
