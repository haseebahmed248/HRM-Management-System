<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $templateName = 'Payslip Generated';

    /**
     * Seed the "Payslip Generated" email template so existing installs (not just
     * fresh seeds) can notify employees when their payslip is generated.
     */
    public function up(): void
    {
        // Idempotent: skip if already present.
        $existing = DB::table('email_templates')->where('name', $this->templateName)->first();
        if ($existing) {
            return;
        }

        $now = now();

        $templateId = DB::table('email_templates')->insertGetId([
            'name'       => $this->templateName,
            'from'       => 'HRM',
            'user_id'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subject = 'Your payslip for {pay_period} is ready';
        $content = '<p>Hello <strong>{employee_name}</strong>,</p>'
            . '<p>Your payslip has been generated on <strong>{app_name}</strong>.</p>'
            . '<p><strong>Payslip Details:</strong></p>'
            . '<ul>'
            . '<li>Payslip Number: {payslip_number}</li>'
            . '<li>Pay Period: {pay_period}</li>'
            . '<li>Pay Date: {pay_date}</li>'
            . '</ul>'
            . '<p>You can view and download your payslip by logging in here: '
            . '<a href="{app_url}">{app_url}</a></p>'
            . '<p>Best regards,<br>{app_name} Team</p>';

        // Seed for every language the install supports; all rows share the same
        // English copy (the send path falls back to 'en' anyway).
        $langCodes = ['en'];
        $languageFile = resource_path('lang/language.json');
        if (file_exists($languageFile)) {
            $languages = json_decode(file_get_contents($languageFile), true);
            if (is_array($languages)) {
                $codes = collect($languages)->pluck('code')->filter()->values()->all();
                if (!empty($codes)) {
                    $langCodes = $codes;
                }
            }
        }

        $rows = [];
        foreach ($langCodes as $code) {
            $rows[] = [
                'parent_id'  => $templateId,
                'lang'       => $code,
                'subject'    => $subject,
                'content'    => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('email_template_langs')->insert($rows);

        // Mirror the seeder's per-owner activation row (used by the templates UI).
        if (DB::getSchemaBuilder()->hasTable('user_email_templates')) {
            DB::table('user_email_templates')->insert([
                'template_id' => $templateId,
                'user_id'     => 1,
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        $template = DB::table('email_templates')->where('name', $this->templateName)->first();
        if (!$template) {
            return;
        }
        DB::table('email_template_langs')->where('parent_id', $template->id)->delete();
        if (DB::getSchemaBuilder()->hasTable('user_email_templates')) {
            DB::table('user_email_templates')->where('template_id', $template->id)->delete();
        }
        DB::table('email_templates')->where('id', $template->id)->delete();
    }
};
