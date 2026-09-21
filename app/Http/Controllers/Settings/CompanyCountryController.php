<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Company country picker.
 *
 * Sets the `users.country_code` on the current company, which drives which
 * payroll calculator runs (Zambia vs Tanzania) and lets us auto-apply the
 * matching currency settings so payslips and CSV exports render in the
 * right currency without the admin needing to touch each field.
 */
class CompanyCountryController extends Controller
{
    /**
     * Per-country currency defaults. Keeps the setup a one-click affair
     * for the client's admin instead of a five-field manual update.
     */
    private const COUNTRY_CURRENCY_DEFAULTS = [
        'ZM' => [
            'defaultCurrency'        => 'ZMW',
            'currencySymbol'         => 'K',
            'currencyCode'           => 'ZMW',
            'currencyName'           => 'Zambian Kwacha',
            'currencySymbolPosition' => 'before',
            'currencySymbolSpace'    => '0',
            'decimalFormat'          => '2',
            'thousandsSeparator'     => ',',
        ],
        'TZ' => [
            'defaultCurrency'        => 'TZS',
            'currencySymbol'         => 'TSh',
            'currencyCode'           => 'TZS',
            'currencyName'           => 'Tanzanian Shilling',
            'currencySymbolPosition' => 'before',
            'currencySymbolSpace'    => '1',
            'decimalFormat'          => '0',
            'thousandsSeparator'     => ',',
        ],
    ];

    public function update(Request $request)
    {
        $validated = $request->validate([
            'country_code'   => 'required|string|in:ZM,TZ',
            'apply_currency' => 'sometimes|boolean',
        ]);

        $authUser = Auth::user();

        // Resolve the company owner the same way settings() does (SaaS-aware).
        if (isSaas()) {
            $companyId = in_array($authUser->type, ['superadmin', 'company'])
                ? $authUser->id
                : (getCompanyId($authUser->created_by) ?? $authUser->id);
        } else {
            $companyId = $authUser->type === 'company'
                ? $authUser->id
                : (getCompanyId($authUser->id) ?? $authUser->id);
        }

        $company = User::find($companyId);
        if (! $company) {
            return redirect()->back()->with('error', __('Company not found.'));
        }

        $company->country_code = $validated['country_code'];
        $company->save();

        // Optional: also stamp the currency settings so payslips and CSV
        // exports pick up the correct locale without any extra clicks. This
        // is the piece that makes "TSh on the payslip" automatic.
        if (($validated['apply_currency'] ?? true) && isset(self::COUNTRY_CURRENCY_DEFAULTS[$validated['country_code']])) {
            $defaults = self::COUNTRY_CURRENCY_DEFAULTS[$validated['country_code']];
            foreach ($defaults as $key => $value) {
                Setting::updateOrCreate(
                    ['user_id' => $companyId, 'key' => $key],
                    ['value'   => $value]
                );
            }
        }

        \App\Models\AuditLog::record(
            'system',
            'system_change',
            'Company Country',
            'Company country set to ' . $validated['country_code']
                . (($validated['apply_currency'] ?? true) ? ' (currency auto-applied)' : '')
        );

        return redirect()->back()->with('success', __('Company country updated successfully.'));
    }
}
