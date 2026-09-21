<?php

namespace App\Http\Controllers;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Basic Tanzania statutory reports + payroll summary CSV.
 *
 * Kept intentionally lean per the $300 scope: CSVs covering PAYE, NSSF,
 * SDL, WCF and a full payroll summary. All read from PayrollEntry rows
 * created by PayrollRun::processEmployeePayrollTanzania().
 *
 * Reports intentionally mirror the ZambiaReportController file/naming
 * pattern so the client's admin gets the same UX in either mode.
 */
class TanzaniaReportController extends Controller
{
    // ─── Index Page ──────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage-payroll-runs')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $payrollRuns = PayrollRun::whereIn('created_by', getCompanyAndUsersId())
            ->whereIn('status', ['completed', 'pending_approval', 'final'])
            ->orderBy('pay_period_start', 'desc')
            ->get(['id', 'title', 'pay_period_start', 'pay_period_end', 'pay_date', 'status']);

        return Inertia::render('hr/tanzania-reports/index', [
            'payrollRuns' => $payrollRuns,
        ]);
    }

    // ─── PAYE report ────────────────────────────────────────────────────────

    public function paye(Request $request)
    {
        $request->validate(['payroll_run_id' => 'required|exists:payroll_runs,id']);
        $run     = $this->run($request->payroll_run_id);
        $entries = $this->entries($run->id);
        $filename = 'tanzania-paye-' . $run->pay_period_start->format('Y-m') . '.csv';

        return $this->stream($filename, function ($handle) use ($run, $entries) {
            fputcsv($handle, ['Tanzania PAYE Report — ' . $run->pay_period_start->format('F Y')]);
            fputcsv($handle, ['Pay period', $run->pay_period_start->format('d M Y') . ' to ' . $run->pay_period_end->format('d M Y')]);
            fputcsv($handle, []);
            fputcsv($handle, ['Employee', 'Employee Code', 'Gross Pay (TSh)', 'PAYE Deducted (TSh)']);
            $totalGross = 0;
            $totalPaye  = 0;
            foreach ($entries as $entry) {
                $paye = $this->pick($entry->deductions_breakdown, 'tanzania_paye');
                $totalGross += (float) $entry->gross_pay;
                $totalPaye  += $paye;
                fputcsv($handle, [
                    $entry->employee?->name ?? $entry->employee_name ?? 'Unknown',
                    $entry->employee?->employee?->employee_code ?? '',
                    number_format($entry->gross_pay, 2, '.', ''),
                    number_format($paye, 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL', '', number_format($totalGross, 2, '.', ''), number_format($totalPaye, 2, '.', '')]);
        });
    }

    // ─── NSSF report ────────────────────────────────────────────────────────

    public function nssf(Request $request)
    {
        $request->validate(['payroll_run_id' => 'required|exists:payroll_runs,id']);
        $run     = $this->run($request->payroll_run_id);
        $entries = $this->entries($run->id);
        $filename = 'tanzania-nssf-' . $run->pay_period_start->format('Y-m') . '.csv';

        return $this->stream($filename, function ($handle) use ($run, $entries) {
            fputcsv($handle, ['Tanzania NSSF Report — ' . $run->pay_period_start->format('F Y')]);
            fputcsv($handle, ['Pay period', $run->pay_period_start->format('d M Y') . ' to ' . $run->pay_period_end->format('d M Y')]);
            fputcsv($handle, []);
            fputcsv($handle, ['Employee', 'Employee Code', 'Gross Pay (TSh)', 'Employee 10% (TSh)', 'Employer 10% (TSh)', 'Total 20% (TSh)']);
            $totals = ['gross' => 0.0, 'emp' => 0.0, 'er' => 0.0];
            foreach ($entries as $entry) {
                $ee = $this->pick($entry->deductions_breakdown, 'tanzania_nssf_employee');
                $er = $this->pickEarning($entry->earnings_breakdown, 'tanzania_nssf_employer');
                $totals['gross'] += (float) $entry->gross_pay;
                $totals['emp']   += $ee;
                $totals['er']    += $er;
                fputcsv($handle, [
                    $entry->employee?->name ?? $entry->employee_name ?? 'Unknown',
                    $entry->employee?->employee?->employee_code ?? '',
                    number_format($entry->gross_pay, 2, '.', ''),
                    number_format($ee, 2, '.', ''),
                    number_format($er, 2, '.', ''),
                    number_format($ee + $er, 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL', '',
                number_format($totals['gross'], 2, '.', ''),
                number_format($totals['emp'], 2, '.', ''),
                number_format($totals['er'], 2, '.', ''),
                number_format($totals['emp'] + $totals['er'], 2, '.', ''),
            ]);
        });
    }

    // ─── SDL report ─────────────────────────────────────────────────────────

    public function sdl(Request $request)
    {
        $request->validate(['payroll_run_id' => 'required|exists:payroll_runs,id']);
        $run     = $this->run($request->payroll_run_id);
        $entries = $this->entries($run->id);
        $filename = 'tanzania-sdl-' . $run->pay_period_start->format('Y-m') . '.csv';

        return $this->stream($filename, function ($handle) use ($run, $entries) {
            fputcsv($handle, ['Tanzania SDL Report — ' . $run->pay_period_start->format('F Y')]);
            fputcsv($handle, ['Employer only. Applies when the company has 10 or more employees.']);
            fputcsv($handle, []);
            fputcsv($handle, ['Employee', 'Gross Pay (TSh)', 'SDL 3.5% (TSh)']);
            $totalG = 0.0; $totalS = 0.0;
            foreach ($entries as $entry) {
                $sdl = $this->pickEarning($entry->earnings_breakdown, 'tanzania_sdl');
                $totalG += (float) $entry->gross_pay;
                $totalS += $sdl;
                fputcsv($handle, [
                    $entry->employee?->name ?? $entry->employee_name ?? 'Unknown',
                    number_format($entry->gross_pay, 2, '.', ''),
                    number_format($sdl, 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL',
                number_format($totalG, 2, '.', ''),
                number_format($totalS, 2, '.', ''),
            ]);
        });
    }

    // ─── WCF report ─────────────────────────────────────────────────────────

    public function wcf(Request $request)
    {
        $request->validate(['payroll_run_id' => 'required|exists:payroll_runs,id']);
        $run     = $this->run($request->payroll_run_id);
        $entries = $this->entries($run->id);
        $filename = 'tanzania-wcf-' . $run->pay_period_start->format('Y-m') . '.csv';

        return $this->stream($filename, function ($handle) use ($run, $entries) {
            fputcsv($handle, ['Tanzania WCF Report — ' . $run->pay_period_start->format('F Y')]);
            fputcsv($handle, ['Employer only. Workers Compensation Fund at 0.5% of monthly payroll.']);
            fputcsv($handle, []);
            fputcsv($handle, ['Employee', 'Gross Pay (TSh)', 'WCF 0.5% (TSh)']);
            $totalG = 0.0; $totalW = 0.0;
            foreach ($entries as $entry) {
                $wcf = $this->pickEarning($entry->earnings_breakdown, 'tanzania_wcf');
                $totalG += (float) $entry->gross_pay;
                $totalW += $wcf;
                fputcsv($handle, [
                    $entry->employee?->name ?? $entry->employee_name ?? 'Unknown',
                    number_format($entry->gross_pay, 2, '.', ''),
                    number_format($wcf, 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL',
                number_format($totalG, 2, '.', ''),
                number_format($totalW, 2, '.', ''),
            ]);
        });
    }

    // ─── Payroll run summary CSV ────────────────────────────────────────────

    public function payrollSummary(Request $request)
    {
        $request->validate(['payroll_run_id' => 'required|exists:payroll_runs,id']);
        $run     = $this->run($request->payroll_run_id);
        $entries = $this->entries($run->id);
        $filename = 'tanzania-payroll-summary-' . $run->pay_period_start->format('Y-m') . '.csv';

        return $this->stream($filename, function ($handle) use ($run, $entries) {
            fputcsv($handle, ['Tanzania Payroll Summary — ' . $run->pay_period_start->format('F Y')]);
            fputcsv($handle, ['Pay period', $run->pay_period_start->format('d M Y') . ' to ' . $run->pay_period_end->format('d M Y')]);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Employee', 'Employee Code', 'Basic Salary (TSh)', 'Gross Pay (TSh)',
                'PAYE (TSh)', 'NSSF Employee (TSh)', 'NSSF Employer (TSh)',
                'SDL (TSh)', 'WCF (TSh)',
                'Total Deductions (TSh)', 'Net Pay (TSh)',
            ]);
            $sum = [
                'basic' => 0.0, 'gross' => 0.0, 'paye' => 0.0,
                'nssf_e' => 0.0, 'nssf_r' => 0.0, 'sdl' => 0.0, 'wcf' => 0.0,
                'ded' => 0.0, 'net' => 0.0,
            ];
            foreach ($entries as $entry) {
                $paye  = $this->pick($entry->deductions_breakdown, 'tanzania_paye');
                $nssfE = $this->pick($entry->deductions_breakdown, 'tanzania_nssf_employee');
                $nssfR = $this->pickEarning($entry->earnings_breakdown, 'tanzania_nssf_employer');
                $sdl   = $this->pickEarning($entry->earnings_breakdown, 'tanzania_sdl');
                $wcf   = $this->pickEarning($entry->earnings_breakdown, 'tanzania_wcf');

                $sum['basic']  += (float) $entry->basic_salary;
                $sum['gross']  += (float) $entry->gross_pay;
                $sum['paye']   += $paye;
                $sum['nssf_e'] += $nssfE;
                $sum['nssf_r'] += $nssfR;
                $sum['sdl']    += $sdl;
                $sum['wcf']    += $wcf;
                $sum['ded']    += (float) $entry->total_deductions;
                $sum['net']    += (float) $entry->net_pay;

                fputcsv($handle, [
                    $entry->employee?->name ?? $entry->employee_name ?? 'Unknown',
                    $entry->employee?->employee?->employee_code ?? '',
                    number_format($entry->basic_salary, 2, '.', ''),
                    number_format($entry->gross_pay, 2, '.', ''),
                    number_format($paye, 2, '.', ''),
                    number_format($nssfE, 2, '.', ''),
                    number_format($nssfR, 2, '.', ''),
                    number_format($sdl, 2, '.', ''),
                    number_format($wcf, 2, '.', ''),
                    number_format($entry->total_deductions, 2, '.', ''),
                    number_format($entry->net_pay, 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);
            fputcsv($handle, [
                'TOTAL', '',
                number_format($sum['basic'], 2, '.', ''),
                number_format($sum['gross'], 2, '.', ''),
                number_format($sum['paye'], 2, '.', ''),
                number_format($sum['nssf_e'], 2, '.', ''),
                number_format($sum['nssf_r'], 2, '.', ''),
                number_format($sum['sdl'], 2, '.', ''),
                number_format($sum['wcf'], 2, '.', ''),
                number_format($sum['ded'], 2, '.', ''),
                number_format($sum['net'], 2, '.', ''),
            ]);
        });
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function run(int $id): PayrollRun
    {
        return PayrollRun::whereIn('created_by', getCompanyAndUsersId())
            ->where('id', $id)
            ->firstOrFail();
    }

    private function entries(int $runId)
    {
        return PayrollEntry::with('employee.employee')
            ->where('payroll_run_id', $runId)
            ->get();
    }

    /**
     * Pick the amount for a given tag from the deductions breakdown JSON.
     */
    private function pick($breakdown, string $type): float
    {
        if (! is_array($breakdown)) return 0.0;
        foreach ($breakdown as $line) {
            if (($line['type'] ?? null) === $type) {
                return (float) ($line['amount'] ?? 0);
            }
        }
        return 0.0;
    }

    /**
     * Same as pick() but reads from the earnings_breakdown (employer
     * contributions like NSSF employer, SDL, WCF live there).
     */
    private function pickEarning($breakdown, string $type): float
    {
        return $this->pick($breakdown, $type);
    }

    /**
     * Streamed CSV response so large runs do not eat memory. The `$writer`
     * callback receives an open write handle and calls fputcsv() as it likes.
     */
    private function stream(string $filename, callable $writer): StreamedResponse
    {
        $callback = function () use ($writer) {
            $handle = fopen('php://output', 'w');
            $writer($handle);
            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
