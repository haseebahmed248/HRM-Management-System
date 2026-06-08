<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSalary;
use App\Models\PayrollEntry;
use App\Models\SalaryComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SalaryComponentController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->can('manage-salary-components')) {
            $query = SalaryComponent::with(['creator'])->where(function ($q) {
                if (Auth::user()->can('manage-any-salary-components')) {
                    $q->whereIn('created_by',  getCompanyAndUsersId());
                } elseif (Auth::user()->can('manage-own-salary-components')) {
                    $q->where('created_by', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            // Handle search
            if ($request->has('search') && !empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            }

            // Handle type filter
            if ($request->has('type') && !empty($request->type) && $request->type !== 'all') {
                $query->where('type', $request->type);
            }

            // Handle calculation type filter
            if ($request->has('calculation_type') && !empty($request->calculation_type) && $request->calculation_type !== 'all') {
                $query->where('calculation_type', $request->calculation_type);
            }

            // Handle status filter
            if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Handle sorting
            if ($request->has('sort_field') && !empty($request->sort_field)) {
                $sortField = $request->sort_field;
                $sortDirection = $request->sort_direction ?? 'asc';
                
                if ($sortField === 'name') {
                    $query->orderBy('name', $sortDirection);
                } else {
                    $query->orderBy('id', 'desc');
                }
            } else {
                $query->orderBy('id', 'desc');
            }

            $salaryComponents = $query->paginate($request->per_page ?? 10);

            return Inertia::render('hr/salary-components/index', [
                'salaryComponents' => $salaryComponents,
                'filters' => $request->all(['search', 'type', 'calculation_type', 'status', 'sort_field', 'sort_direction', 'per_page']),
            ]);
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:earning,deduction',
            // track-a/10: `zambia_pension` marks a deduction component as
            // qualifying for PAYE relief (capped per Zambia Tax Settings).
            // It behaves like `fixed` for amount purposes — uses default_amount.
            'calculation_type' => 'required|in:fixed,percentage,zambia_pension',
            'default_amount' => 'required_if:calculation_type,fixed,zambia_pension|nullable|numeric|min:0',
            'percentage_of_basic' => 'required_if:calculation_type,percentage|nullable|numeric|min:0|max:100',
            'is_taxable' => 'boolean',
            'is_mandatory' => 'boolean',
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated['created_by'] = creatorId();
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['is_taxable'] = $validated['is_taxable'] ?? true;
        $validated['is_mandatory'] = $validated['is_mandatory'] ?? false;

        // Set default values based on calculation type
        // track-a/10: zambia_pension uses default_amount like 'fixed'.
        if (in_array($validated['calculation_type'], ['fixed', 'zambia_pension'], true)) {
            $validated['percentage_of_basic'] = null;
        } else {
            $validated['default_amount'] = 0;
        }

        // Check if component with same name already exists
        $exists = SalaryComponent::where('name', $validated['name'])
            ->whereIn('created_by', getCompanyAndUsersId())
            ->exists();

        if ($exists) {
            return redirect()->back()->with('error', __('Salary component with this name already exists.'));
        }

        SalaryComponent::create($validated);

        return redirect()->back()->with('success', __('Salary component created successfully.'));
    }

    public function update(Request $request, $salaryComponentId)
    {
        $salaryComponent = SalaryComponent::where('id', $salaryComponentId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if ($salaryComponent) {
            try {
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'description' => 'nullable|string',
                    'type' => 'required|in:earning,deduction',
                    // track-a/10: accept zambia_pension (behaves like fixed for amount)
                    'calculation_type' => 'required|in:fixed,percentage,zambia_pension',
                    'default_amount' => 'required_if:calculation_type,fixed,zambia_pension|nullable|numeric|min:0',
                    'percentage_of_basic' => 'required_if:calculation_type,percentage|nullable|numeric|min:0|max:100',
                    'is_taxable' => 'boolean',
                    'is_mandatory' => 'boolean',
                    'status' => 'nullable|in:active,inactive',
                ]);

                // Set default values based on calculation type
                // track-a/10: zambia_pension uses default_amount like 'fixed'.
                if (in_array($validated['calculation_type'], ['fixed', 'zambia_pension'], true)) {
                    $validated['percentage_of_basic'] = null;
                } else {
                    $validated['default_amount'] = 0;
                }

                // Check if component with same name already exists (excluding current)
                $exists = SalaryComponent::where('name', $validated['name'])
                    ->whereIn('created_by', getCompanyAndUsersId())
                    ->where('id', '!=', $salaryComponentId)
                    ->exists();

                if ($exists) {
                    return redirect()->back()->with('error', __('Salary component with this name already exists.'));
                }

                $salaryComponent->update($validated);

                return redirect()->back()->with('success', __('Salary component updated successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update salary component'));
            }
        } else {
            return redirect()->back()->with('error', __('Salary component Not Found.'));
        }
    }

    public function destroy($salaryComponentId)
    {
        $salaryComponent = SalaryComponent::where('id', $salaryComponentId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if (!$salaryComponent) {
            return redirect()->back()->with('error', __('Salary component Not Found.'));
        }

        // Block deletion if the component is referenced in any employee salary or payroll entry.
        // Mark it inactive instead so history is preserved.
        $usedInSalaries = EmployeeSalary::whereJsonContains('components', ['id' => (int)$salaryComponentId])
            ->whereIn('created_by', getCompanyAndUsersId())
            ->exists();

        $usedInPayroll = PayrollEntry::whereIn('created_by', getCompanyAndUsersId())
            ->where(function ($q) use ($salaryComponentId) {
                $q->whereRaw("JSON_SEARCH(earnings_breakdown, 'one', ?, null, '$[*].component_id') IS NOT NULL", [$salaryComponentId])
                  ->orWhereRaw("JSON_SEARCH(deductions_breakdown, 'one', ?, null, '$[*].component_id') IS NOT NULL", [$salaryComponentId]);
            })
            ->exists();

        if ($usedInSalaries || $usedInPayroll) {
            // Cannot delete — mark inactive instead
            $salaryComponent->status = 'inactive';
            $salaryComponent->save();
            return redirect()->back()->with('success', __('Salary component has been used in payroll and cannot be deleted. It has been marked as inactive instead.'));
        }

        try {
            $salaryComponent->delete();
            return redirect()->back()->with('success', __('Salary component deleted successfully'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to delete salary component'));
        }
    }

    public function toggleStatus($salaryComponentId)
    {
        $salaryComponent = SalaryComponent::where('id', $salaryComponentId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if ($salaryComponent) {
            try {
                $salaryComponent->status = $salaryComponent->status === 'active' ? 'inactive' : 'active';
                $salaryComponent->save();

                return redirect()->back()->with('success', __('Salary component status updated successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update salary component status'));
            }
        } else {
            return redirect()->back()->with('error', __('Salary component Not Found.'));
        }
    }

    // ─── track-a/12: bulk import ─────────────────────────────────────────────
    // Mirrors the EmployeeController XLSX/CSV import pipeline:
    //   GET /hr/salary-components/download-template → XLSX template
    //   POST /hr/salary-components/parse            → previews + headers
    //   POST /hr/salary-components/import           → creates records
    // The import is idempotent — duplicate names (within the same tenant)
    // are skipped, not errored, so the user can re-upload a tweaked file.
    public function downloadTemplate()
    {
        if (! Auth::user()->can('import-salary-components')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Salary Components');

            $headers = [
                'Name', 'Description', 'Type', 'Calculation Type',
                'Default Amount', 'Percentage Of Basic',
                'Taxable', 'Mandatory', 'Status',
            ];

            foreach ($headers as $i => $h) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($col . '1', $h);
                $sheet->getColumnDimensionByColumn($i + 1)->setWidth(22);
            }
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
            ]);

            // Sample rows showing each calculation_type
            $samples = [
                ['Housing Allowance',  'Monthly housing stipend', 'earning',   'fixed',          '1500', '',   'yes', 'no', 'active'],
                ['Performance Bonus',  '10% of basic salary',     'earning',   'percentage',     '',     '10', 'yes', 'no', 'active'],
                ['Pension Contribution','Employee NAPSA top-up',  'deduction', 'zambia_pension', '500',  '',   'no',  'no', 'active'],
                ['Loan Repayment',     'Monthly loan deduction',  'deduction', 'fixed',          '750',  '',   'no',  'no', 'active'],
            ];
            foreach ($samples as $rowIdx => $row) {
                foreach ($row as $colIdx => $val) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                    $sheet->setCellValue($col . ($rowIdx + 2), $val);
                }
            }

            // Hint row
            $hintRow = count($samples) + 2;
            $sheet->setCellValue('A' . $hintRow, 'Allowed Type: earning | deduction');
            $sheet->setCellValue('D' . $hintRow, 'Allowed Calculation Type: fixed | percentage | zambia_pension');
            $sheet->getStyle('A' . $hintRow . ':' . $lastCol . $hintRow)->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '856404']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF3CD']],
            ]);

            $tempFile = tempnam(sys_get_temp_dir(), 'sc_template_') . '.xlsx';
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tempFile);
            return response()->download($tempFile, 'Salary_Components_Import_Template.xlsx')->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Failed to generate template: :error', ['error' => $e->getMessage()]));
        }
    }

    public function parseFile(Request $request)
    {
        if (! Auth::user()->can('import-salary-components')) {
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->getMessageBag()->first()]);
        }

        try {
            $file = $request->file('file');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $highestCol = $worksheet->getHighestDataColumn();
            $highestRow = $worksheet->getHighestDataRow();
            $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

            $getCell = fn(int $c, int $r) =>
                $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r)->getValue();

            // Header detection mirrors EmployeeController::parseFile.
            $headerRowNum = 1;
            for ($r = 1; $r <= min(10, $highestRow); $r++) {
                $nonEmpty = 0;
                for ($c = 1; $c <= $highestColIdx; $c++) {
                    $v = $getCell($c, $r);
                    if ($v !== null && $v !== '') $nonEmpty++;
                }
                if ($nonEmpty >= 3) { $headerRowNum = $r; break; }
            }

            $headers = [];
            for ($c = 1; $c <= $highestColIdx; $c++) {
                $v = $getCell($c, $headerRowNum);
                if ($v !== null && $v !== '') $headers[] = trim((string) $v);
            }

            $previewData = [];
            for ($row = $headerRowNum + 1; $row <= $highestRow; $row++) {
                $rowData = [];
                $hasData = false;
                for ($c = 1; $c <= $highestColIdx; $c++) {
                    $val = (string) $getCell($c, $row);
                    if (($c - 1) < count($headers)) {
                        $rowData[$headers[$c - 1]] = $val;
                        if ($val !== '') $hasData = true;
                    }
                }
                // Skip the "Allowed Type:" hint row in the template
                $firstVal = strtolower(trim((string) ($rowData[$headers[0] ?? ''] ?? '')));
                if (str_starts_with($firstVal, 'allowed')) continue;
                if ($hasData) $previewData[] = $rowData;
            }

            return response()->json(['excelColumns' => $headers, 'previewData' => $previewData]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('Failed to parse file: :error', ['error' => $e->getMessage()])]);
        }
    }

    public function fileImport(Request $request)
    {
        if (! Auth::user()->can('import-salary-components')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $validator = Validator::make($request->all(), ['data' => 'required|array']);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($request->data as $idx => $row) {
            try {
                // Normalise keys — the parser uses the literal column headers,
                // so map them to model fields.
                $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
                if ($name === '') { $skipped++; continue; }

                $type = strtolower(trim((string) ($row['Type'] ?? $row['type'] ?? 'earning')));
                if (! in_array($type, ['earning', 'deduction'], true)) {
                    $errors[] = __('Row :n: invalid Type :t', ['n' => $idx + 1, 't' => $type]);
                    $skipped++;
                    continue;
                }

                $calc = strtolower(str_replace([' ', '-'], '_', trim((string) ($row['Calculation Type'] ?? $row['calculation_type'] ?? 'fixed'))));
                if (! in_array($calc, ['fixed', 'percentage', 'zambia_pension'], true)) {
                    $errors[] = __('Row :n: invalid Calculation Type :c', ['n' => $idx + 1, 'c' => $calc]);
                    $skipped++;
                    continue;
                }

                $defaultAmount = $row['Default Amount'] ?? $row['default_amount'] ?? null;
                $percentage    = $row['Percentage Of Basic'] ?? $row['percentage_of_basic'] ?? null;

                $boolish = fn($v) => in_array(strtolower(trim((string) $v)), ['yes', '1', 'true', 'y'], true);

                $payload = [
                    'name'                => $name,
                    'description'         => $row['Description'] ?? $row['description'] ?? null,
                    'type'                => $type,
                    'calculation_type'    => $calc,
                    'default_amount'      => in_array($calc, ['fixed', 'zambia_pension'], true) ? (float) ($defaultAmount ?: 0) : 0,
                    'percentage_of_basic' => $calc === 'percentage' ? (float) ($percentage ?: 0) : null,
                    'is_taxable'          => $boolish($row['Taxable'] ?? $row['is_taxable'] ?? 'yes'),
                    'is_mandatory'        => $boolish($row['Mandatory'] ?? $row['is_mandatory'] ?? 'no'),
                    'status'              => in_array(strtolower(trim((string) ($row['Status'] ?? $row['status'] ?? 'active'))), ['active', 'inactive'], true)
                        ? strtolower(trim((string) ($row['Status'] ?? $row['status'] ?? 'active')))
                        : 'active',
                    'created_by'          => creatorId(),
                ];

                // Idempotent: skip if a component with the same name already exists in this tenant.
                $exists = SalaryComponent::where('name', $payload['name'])
                    ->whereIn('created_by', getCompanyAndUsersId())
                    ->exists();
                if ($exists) { $skipped++; continue; }

                SalaryComponent::create($payload);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = __('Row :n: :err', ['n' => $idx + 1, 'err' => $e->getMessage()]);
                $skipped++;
            }
        }

        $msg = __(':imported imported, :skipped skipped.', ['imported' => $imported, 'skipped' => $skipped]);
        if (! empty($errors)) {
            $msg .= ' ' . implode(' | ', array_slice($errors, 0, 3));
        }
        return redirect()->back()->with($imported > 0 ? 'success' : 'error', $msg);
    }
}
