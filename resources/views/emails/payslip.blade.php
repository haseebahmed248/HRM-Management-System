<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip for {{ $period }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f7f8;color:#1f2937;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;">
        <tr>
            <td style="padding:28px;">
                <p style="margin:0 0 16px;font-size:16px;">Hello {{ $employeeName }},</p>
                <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">Please find attached your payslip for {{ $period }}.</p>
                <p style="margin:0;font-size:14px;line-height:1.6;">Regards,<br>{{ config('app.name') }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
