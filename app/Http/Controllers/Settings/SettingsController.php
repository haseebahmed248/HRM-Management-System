<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Models\Currency;
use App\Models\PaymentSetting;
use App\Models\Webhook;
use App\Models\IpRestriction;
use App\Models\NocTemplate;
use App\Models\ExperienceCertificateTemplate;
use App\Models\JoiningLetterTemplate;
use App\Support\PayslipTemplateConfig;

class SettingsController extends Controller
{
    public function index()
    {
        $systemSettings = settings();
        $companyId = getCompanyId(auth()->id()) ?? auth()->id();
        $currencies = Currency::all();
        $paymentSettings = PaymentSetting::getUserSettings(auth()->id());
        $webhooks = Webhook::where('user_id', auth()->id())->get();
        $ipRestrictions = IpRestriction::whereIn('created_by', getCompanyAndUsersId())
            ->orderBy('id', 'desc')->get();

        $zektoSettings = [
            'zkteco_api_url'    => $systemSettings['zkteco_api_url']    ?? '',
            'zkteco_username'   => $systemSettings['zkteco_username']   ?? '',
            'zkteco_password'   => $systemSettings['zkteco_password']   ?? '',
            'zkteco_auth_token' => $systemSettings['zkteco_auth_token'] ?? '',
        ];

        $nocTemplates                   = NocTemplate::where('created_by', Auth::id())->get();
        $joiningLetterTemplates         = JoiningLetterTemplate::where('created_by', Auth::id())->get();
        $experienceCertificateTemplates = ExperienceCertificateTemplate::where('created_by', Auth::id())->get();

        // Zambia Tax Settings
        // AFTER — replace with this
$zambiaDefaults = [
    'zambia_paye_slab_1_min'     => '0',
    'zambia_paye_slab_1_max'     => '5100',
    'zambia_paye_slab_1_rate'    => '0',
    'zambia_paye_slab_2_min'     => '5100.01',
    'zambia_paye_slab_2_max'     => '7100',
    'zambia_paye_slab_2_rate'    => '20',
    'zambia_paye_slab_3_min'     => '7100.01',
    'zambia_paye_slab_3_max'     => '9200',
    'zambia_paye_slab_3_rate'    => '30',
    'zambia_paye_slab_4_min'     => '9200.01',
    'zambia_paye_slab_4_max'     => '999999999',
    'zambia_paye_slab_4_rate'    => '37',
    'zambia_napsa_employee_rate' => '5',
    'zambia_napsa_employer_rate' => '5',
    'zambia_napsa_monthly_cap'   => '1073.20',
    'zambia_nhima_employee_rate' => '1',
    'zambia_nhima_employer_rate' => '1',
    'zambia_sdl_rate'            => '0.5',
];

// Zambia tax settings are global — always read from the Super Admin so every
// company sees (and payroll uses) the same Super-Admin-controlled values.
$zambiaTaxSettings = array_merge(
    $zambiaDefaults,
    Setting::where('user_id', getSuperAdminId() ?? creatorId())
        ->where('key', 'like', 'zambia_%')
        ->pluck('value', 'key')
        ->toArray()
);

// Tanzania tax settings — same treatment as Zambia. 2026 TRA defaults per the
// smartlinkerp reference the client shared. Values match the seeder migration.
$tanzaniaDefaults = [
    'tanzania_paye_slab_1_min'  => '0',
    'tanzania_paye_slab_1_max'  => '270000',
    'tanzania_paye_slab_1_rate' => '0',
    'tanzania_paye_slab_2_min'  => '270001',
    'tanzania_paye_slab_2_max'  => '520000',
    'tanzania_paye_slab_2_rate' => '8',
    'tanzania_paye_slab_3_min'  => '520001',
    'tanzania_paye_slab_3_max'  => '760000',
    'tanzania_paye_slab_3_rate' => '20',
    'tanzania_paye_slab_4_min'  => '760001',
    'tanzania_paye_slab_4_max'  => '1000000',
    'tanzania_paye_slab_4_rate' => '25',
    'tanzania_paye_slab_5_min'  => '1000001',
    'tanzania_paye_slab_5_rate' => '30',
    'tanzania_nssf_employee_rate' => '10',
    'tanzania_nssf_employer_rate' => '10',
    'tanzania_sdl_rate'                => '3.5',
    'tanzania_sdl_employee_threshold'  => '10',
    'tanzania_wcf_rate'                => '0.5',
];

$tanzaniaTaxSettings = array_merge(
    $tanzaniaDefaults,
    Setting::where('user_id', getSuperAdminId() ?? creatorId())
        ->where('key', 'like', 'tanzania_%')
        ->pluck('value', 'key')
        ->toArray()
);

        $employeeIdSettings = [
            'employee_id_prefix'  => $systemSettings['employee_id_prefix'] ?? 'EMP',
            'employee_id_padding' => $systemSettings['employee_id_padding'] ?? 6,
        ];

        return Inertia::render('settings/index', [
            'systemSettings'                  => $systemSettings,
            'settings'                        => $systemSettings,
            'employeeIdSettings'              => $employeeIdSettings,
            'cacheSize'                       => getCacheSize(),
            'currencies'                      => $currencies,
            'timezones'                       => config('timezones'),
            'dateFormats'                     => config('dateformat'),
            'timeFormats'                     => config('timeformat'),
            'paymentSettings'                 => $paymentSettings,
            'webhooks'                        => $webhooks,
            'zektoSettings'                   => $zektoSettings,
            'ipRestrictions'                  => $ipRestrictions,
            'nocTemplates'                    => $nocTemplates,
            'joiningLetterTemplates'          => $joiningLetterTemplates,
            'experienceCertificateTemplates'  => $experienceCertificateTemplates,
            'zambiaTaxSettings'               => $zambiaTaxSettings,
            'tanzaniaTaxSettings'             => $tanzaniaTaxSettings,
            'companyCountry'                  => \App\Models\User::find($companyId)?->country_code ?? 'ZM',
            'payslipTemplateConfig'           => PayslipTemplateConfig::forCompany($companyId),
            'payrollJournalAccounts'          => \App\Support\PayrollJournalAccounts::editableForCompany($companyId),
            'payrollJournalAccountLabels'     => \App\Support\PayrollJournalAccounts::EDITABLE,
        ]);
    }
}
