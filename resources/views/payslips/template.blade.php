@php
    $templateConfig = $templateConfig ?? \App\Support\PayslipTemplateConfig::defaults();
    $renderMode = $renderMode ?? 'pdf';
    $leaveBalances = collect($leaveBalances ?? []);
    $employeeFieldValues = $employeeFieldValues ?? [
        'employee_name' => $payrollEntry->employee?->name ?? $payslip->employee_name ?? 'N/A',
        'nrc' => $employeeData?->nrc ?: 'N/A',
        'designation' => $employeeData?->designation?->name ?: 'N/A',
        'pay_period' => $payrollEntry->payrollRun->pay_period_start->format('d M Y') . ' - ' . $payrollEntry->payrollRun->pay_period_end->format('d M Y'),
        'tpin' => $employeeData?->tpin ?: 'N/A',
        'napsa_number' => $employeeData?->napsa_number ?: 'N/A',
        'nhima_number' => $employeeData?->nhima_number ?: 'N/A',
        'date_of_joining' => $employeeData?->date_of_joining ? \Carbon\Carbon::parse($employeeData->date_of_joining)->format('d M Y') : 'N/A',
        'bank_name' => $employeeData?->bank_name ?: 'N/A',
        'account_number' => $employeeData?->account_number ?: 'N/A',
    ];

    $visibleEmployeeFields = array_values(array_filter(
        $templateConfig['employee_fields'] ?? [],
        fn ($field) => (bool) ($field['show'] ?? false)
    ));
    $employeeFieldRows = array_chunk($visibleEmployeeFields, 2);

    $rawEarnings = $payrollEntry->earnings_breakdown ?? [];
    $rawDeductions = $payrollEntry->deductions_breakdown ?? [];
    $earningsList = [];
    foreach ($rawEarnings as $key => $value) {
        if (is_array($value) && isset($value['name'])) {
            if (in_array($value['type'] ?? '', ['zambia_napsa_employer', 'zambia_nhima_employer', 'zambia_sdl'])) {
                continue;
            }
            $earningsList[] = ['name' => $value['name'], 'amount' => $value['amount']];
        } else {
            $earningsList[] = ['name' => $key, 'amount' => $value];
        }
    }

    $deductionsList = [];
    foreach ($rawDeductions as $key => $value) {
        if (is_array($value) && isset($value['name'])) {
            $deductionsList[] = ['name' => $value['name'], 'amount' => $value['amount']];
        } else {
            $deductionsList[] = ['name' => $key, 'amount' => $value];
        }
    }

    if ($payrollEntry->overtime_amount > 0) {
        $earningsList[] = ['name' => 'Overtime Amount', 'amount' => $payrollEntry->overtime_amount];
    }
    if (($payrollEntry->unpaid_leave_deduction ?? 0) > 0) {
        $deductionsList[] = ['name' => 'Unpaid Leave Deduction', 'amount' => $payrollEntry->unpaid_leave_deduction];
    }

    $maxRows = max(count($earningsList), count($deductionsList), 1);
    $totalEarnings = $payrollEntry->total_earnings + $payrollEntry->overtime_amount;
    $totalDeductions = $payrollEntry->total_deductions + ($payrollEntry->unpaid_leave_deduction ?? 0);

    $employerItems = [];
    foreach ($rawEarnings as $value) {
        if (is_array($value) && in_array($value['type'] ?? '', ['zambia_napsa_employer', 'zambia_nhima_employer', 'zambia_sdl'])) {
            $employerItems[] = $value;
        }
    }

    $companyContacts = [];
    if (($templateConfig['header']['show_company_email'] ?? true) && !empty($companySettings['companyEmail'])) {
        $companyContacts[] = 'Email: ' . $companySettings['companyEmail'];
    }
    if (($templateConfig['header']['show_company_phone'] ?? true) && !empty($companySettings['companyMobile'])) {
        $companyContacts[] = 'Phone: ' . $companySettings['companyMobile'];
    }

    $companyName = $companySettings['titleText'] ?? $companySettings['companyName'] ?? config('app.name', 'AfriPay HR');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip - {{ $employeeFieldValues['employee_name'] }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 10mm; }
        body {
            margin: 0;
            padding: 18px;
            background: {{ $renderMode === 'pdf' ? '#ffffff' : '#eef1f2' }};
            color: #172022;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }
        .preview-toolbar {
            display: flex;
            width: 100%;
            max-width: 750px;
            margin: 0 auto 10px;
            justify-content: flex-end;
        }
        .print-button {
            border: 1px solid #1f6d50;
            border-radius: 5px;
            padding: 8px 14px;
            background: #187454;
            color: #ffffff;
            font: 600 13px Arial, sans-serif;
            cursor: pointer;
        }
        .container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid #263437;
            background: #ffffff;
        }
        .header {
            position: relative;
            min-height: 112px;
            padding: 16px 92px 14px;
            border-bottom: 2px solid #187454;
            text-align: center;
        }
        .header-logo,
        .header-qr {
            position: absolute;
            top: 15px;
            width: 72px;
            text-align: center;
        }
        .header-logo { left: 14px; }
        .header-qr { right: 14px; }
        .header-logo img { max-width: 72px; max-height: 58px; }
        .header-qr img { width: 64px; height: 64px; }
        .header-qr-label { margin-top: 2px; color: #526064; font-size: 7px; }
        .company-name { margin-bottom: 3px; font-size: 19px; font-weight: 700; }
        .company-address,
        .company-contact { color: #465458; font-size: 9px; }
        .company-contact { margin-top: 2px; }
        .payslip-title { margin-top: 9px; font-size: 13px; font-weight: 700; text-transform: uppercase; }
        .period-title { margin-top: 2px; color: #465458; font-size: 9px; }
        table { width: 100%; margin: 0; border-collapse: collapse; table-layout: fixed; }
        th,
        td {
            padding: 6px 7px;
            border: 1px solid #d6dcde;
            text-align: left;
            vertical-align: top;
            overflow-wrap: break-word;
        }
        th { background: #f5f7f7; font-weight: 700; }
        .section-header {
            padding: 7px;
            background: #e9efed;
            color: #172d27;
            font-size: 10px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
        }
        .info-label { width: 20%; color: #344347; font-weight: 700; }
        .info-value { width: 30%; }
        .amount { text-align: right; }
        .total-row { background: #f5f7f7; font-weight: 700; }
        .net-salary-row { background: #dff0e9; font-size: 12px; font-weight: 700; }
        .leave-period-label { color: #344347; font-weight: 700; }
        .leave-balance-head th { background: #f7f9f8; color: #465458; font-size: 9px; }
        .employer-row { background: #fff9e9; }
        .section-gap { height: 4px; border: 0; }
        .footer { padding: 10px 12px; border-top: 1px solid #cfd7d9; color: #465458; font-size: 9px; text-align: center; }
        .footer p { margin: 0; }
        .footer-site { margin-top: 3px; color: #187454; font-weight: 700; }
        @media screen and (max-width: 600px) {
            body { padding: 8px; }
            .header { min-height: 150px; padding: 82px 12px 14px; }
            .header-logo,
            .header-qr { top: 12px; width: 62px; }
            .header-logo { left: 12px; }
            .header-qr { right: 12px; }
            .header-logo img { max-width: 62px; max-height: 52px; }
            .header-qr img { width: 54px; height: 54px; }
            .company-name { font-size: 17px; }
            .employee-table tr:not(.section-row) {
                display: grid;
                grid-template-columns: minmax(100px, 0.45fr) minmax(0, 1fr);
            }
            .employee-table .info-label,
            .employee-table .info-value { width: auto; }
            .salary-table th,
            .salary-table td { padding: 5px 4px; font-size: 8px; }
            .salary-table .net-salary-row { font-size: 10px; }
        }
        @media print {
            body { padding: 0; background: #ffffff; }
            .preview-toolbar { display: none !important; }
            .container { max-width: none; border-color: #263437; }
        }
    </style>
</head>
<body>
@if ($renderMode !== 'pdf')
    <div class="preview-toolbar">
        <button type="button" class="print-button" onclick="window.print()">Print payslip</button>
    </div>
@endif

<div class="container" id="payslip-content">
    <div class="header">
        @if (($templateConfig['logo']['show'] ?? true) && !empty($logoDataUri))
            <div class="header-logo"><img src="{{ $logoDataUri }}" alt="Company logo"></div>
        @endif
        @if (($templateConfig['qr']['show'] ?? true) && !empty($qrCodeDataUri))
            <div class="header-qr">
                <img src="{{ $qrCodeDataUri }}" alt="ESS portal QR code">
                <div class="header-qr-label">ESS Portal</div>
            </div>
        @endif
        <div class="company-name">{{ $companyName }}</div>
        @if (!empty($companySettings['companyAddress']))
            <div class="company-address">{{ $companySettings['companyAddress'] }}</div>
        @endif
        @if (count($companyContacts) > 0)
            <div class="company-contact">{{ implode(' | ', $companyContacts) }}</div>
        @endif
        <div class="payslip-title">{{ $templateConfig['header']['title'] ?? 'Salary Slip' }}</div>
        <div class="period-title">{{ $payrollEntry->payrollRun->pay_period_start->format('F Y') }}</div>
    </div>

    @if (($templateConfig['sections']['employee_info'] ?? true) && count($employeeFieldRows) > 0)
        <table class="employee-table">
            <tr class="section-row"><th colspan="4" class="section-header">Employee Information</th></tr>
            @foreach ($employeeFieldRows as $fieldRow)
                <tr>
                    @foreach ($fieldRow as $field)
                        <td class="info-label">{{ $field['label'] }}</td>
                        <td class="info-value">{{ $employeeFieldValues[$field['key']] ?? 'N/A' }}</td>
                    @endforeach
                    @if (count($fieldRow) === 1)
                        <td class="info-label"></td>
                        <td class="info-value"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    @if ($templateConfig['sections']['leave'] ?? true)
        <table>
            <tr><th colspan="4" class="section-header">Leave Details</th></tr>
            <tr>
                <td class="leave-period-label">Paid Leave</td>
                <td>{{ number_format((float) ($payrollEntry->paid_leave_days ?? 0), 2) }}</td>
                <td class="leave-period-label">Unpaid Leave</td>
                <td>{{ number_format((float) ($payrollEntry->unpaid_leave_days ?? 0), 2) }}</td>
            </tr>
            @if ($leaveBalances->isNotEmpty())
                <tr class="leave-balance-head">
                    <th>Leave Type</th>
                    <th class="amount">Entitled</th>
                    <th class="amount">Taken</th>
                    <th class="amount">Balance</th>
                </tr>
                @foreach ($leaveBalances as $balance)
                    <tr>
                        <td>{{ $balance['name'] }}</td>
                        <td class="amount">{{ number_format((float) $balance['entitled'], 2) }}</td>
                        <td class="amount">{{ number_format((float) $balance['taken'], 2) }}</td>
                        <td class="amount">{{ number_format((float) $balance['balance'], 2) }}</td>
                    </tr>
                @endforeach
            @endif
        </table>
    @endif

    @if ($templateConfig['sections']['earnings_deductions'] ?? true)
        <table class="salary-table">
            <tr><th colspan="4" class="section-header">Salary Details</th></tr>
            <tr>
                <th width="35%">Earnings</th>
                <th width="15%" class="amount">Amount (ZMW)</th>
                <th width="35%">Deductions</th>
                <th width="15%" class="amount">Amount (ZMW)</th>
            </tr>
            @for ($index = 0; $index < $maxRows; $index++)
                <tr>
                    <td>{{ $earningsList[$index]['name'] ?? '' }}</td>
                    <td class="amount">{{ isset($earningsList[$index]) ? formatCurrency($earningsList[$index]['amount']) : '' }}</td>
                    <td>{{ $deductionsList[$index]['name'] ?? '' }}</td>
                    <td class="amount">{{ isset($deductionsList[$index]) ? formatCurrency($deductionsList[$index]['amount']) : '' }}</td>
                </tr>
            @endfor
            <tr class="total-row">
                <td>Total Earnings</td>
                <td class="amount">{{ formatCurrency($totalEarnings) }}</td>
                <td>Total Deductions</td>
                <td class="amount">{{ formatCurrency($totalDeductions) }}</td>
            </tr>
            <tr class="net-salary-row">
                <td colspan="3">NET PAY</td>
                <td class="amount">{{ formatCurrency($payrollEntry->net_pay) }}</td>
            </tr>
        </table>
    @endif

    @if (($templateConfig['sections']['employer_contributions'] ?? true) && count($employerItems) > 0)
        <table>
            <tr><th colspan="2" class="section-header">Employer Contributions (HR Record - Not Deducted from Employee)</th></tr>
            @foreach ($employerItems as $item)
                <tr class="employer-row">
                    <td>{{ $item['name'] }}</td>
                    <td class="amount">{{ formatCurrency($item['amount']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        <p><strong>{{ $templateConfig['footer']['text'] ?? 'This is a computer-generated payslip.' }}</strong></p>
        <p class="footer-site">www.afripay-hr.co.zm</p>
    </div>
</div>

@if ($renderMode === 'print')
    <script>
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 250);
        });
    </script>
@endif
</body>
</html>
