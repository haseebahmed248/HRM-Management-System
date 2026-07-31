<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\PayslipTemplateConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Services\StorageConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SystemSettingsController extends Controller
{
    public function updatePayslipTemplate(Request $request)
    {
        $user = $request->user();
        if (!$user->can('manage-settings') || $user->type === 'superadmin') {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $allowedFields = implode(',', PayslipTemplateConfig::EMPLOYEE_FIELD_KEYS);
        $validated = $request->validate([
            'logo.show' => 'required|boolean',
            'qr.show' => 'required|boolean',
            'header.title' => 'required|string|max:100',
            'header.show_company_email' => 'required|boolean',
            'header.show_company_phone' => 'required|boolean',
            'footer.text' => 'required|string|max:500',
            'sections.employee_info' => 'required|boolean',
            'sections.leave' => 'required|boolean',
            'sections.earnings_deductions' => 'required|boolean',
            'sections.employer_contributions' => 'required|boolean',
            'employee_fields' => 'required|array|size:10',
            'employee_fields.*.key' => "required|string|distinct|in:{$allowedFields}",
            'employee_fields.*.label' => 'required|string|max:80',
            'employee_fields.*.show' => 'required|boolean',
        ]);

        $companyId = getCompanyId($user->id) ?? $user->id;
        $config = PayslipTemplateConfig::normalize($validated);

        updateSetting(
            'payslip_template_config',
            json_encode($config, JSON_UNESCAPED_SLASHES),
            $companyId
        );

        \App\Models\AuditLog::record(
            'system',
            'system_change',
            'Payslip Template',
            'Payslip template configuration updated'
        );

        return redirect()->back()->with('success', __('Payslip template updated successfully.'));
    }

    public function updateStatutoryRegistration(Request $request)
    {
        $user = $request->user();
        if ($user->type !== 'company') {
            return redirect()->back()->with('error', __('Only company administrators can update statutory registration details.'));
        }

        $validated = $request->validate([
            'employer_napsa_number' => 'nullable|string|max:100',
            'company_nhima_number' => 'nullable|string|max:100',
            'employer_tpin' => 'nullable|string|max:100',
        ]);

        $companyId = getCompanyId($user->id) ?? $user->id;

        \DB::transaction(function () use ($validated, $companyId) {
            foreach ($validated as $key => $value) {
                updateSetting($key, trim((string) ($value ?? '')), $companyId);
            }
        });

        \App\Models\AuditLog::record(
            'system',
            'system_change',
            'Statutory Registration',
            'Company statutory registration details updated'
        );

        return redirect()->back()->with('success', __('Statutory registration details updated successfully.'));
    }

    public function updateWorkingDays(Request $request)
    {
        $user = $request->user();
        if ($user->type !== 'company' && ! $user->can('update-working-days-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $validated = $request->validate([
            'work_days_pattern' => 'required|string|in:mon_fri,mon_sat,custom',
            'hours_per_day' => 'required|numeric|gt:0|max:24',
            'hours_per_week' => 'required|numeric|gt:0|max:168',
            'working_days_per_month' => 'required|integer|min:1|max:31',
            'working_days' => 'required|array|min:1|max:7',
            'working_days.*' => 'required|integer|between:0,6|distinct',
        ]);

        $companyId = getCompanyId($user->id) ?? $user->id;
        $workingDays = array_values(array_unique(array_map('intval', $validated['working_days'])));
        sort($workingDays);

        if ($validated['work_days_pattern'] === 'mon_fri') {
            $workingDays = [1, 2, 3, 4, 5];
        } elseif ($validated['work_days_pattern'] === 'mon_sat') {
            $workingDays = [1, 2, 3, 4, 5, 6];
        }

        \DB::transaction(function () use ($validated, $workingDays, $companyId) {
            updateSetting('work_days_pattern', $validated['work_days_pattern'], $companyId);
            updateSetting('hours_per_day', (string) $validated['hours_per_day'], $companyId);
            updateSetting('hours_per_week', (string) $validated['hours_per_week'], $companyId);
            updateSetting('working_days_per_month', (string) $validated['working_days_per_month'], $companyId);
            updateSetting('working_days', json_encode($workingDays), $companyId);
        });

        \App\Models\AuditLog::record(
            'system',
            'system_change',
            'Working Days Settings',
            'Working days schedule updated'
        );

        return redirect()->back()->with('success', __('Working days settings updated successfully.'));
    }

    public function update(Request $request)
    {
        try {
            $rules = [
                'defaultLanguage' => 'required|string',
                'dateFormat' => 'required|string',
                'timeFormat' => 'required|string',
                'calendarStartDay' => 'required|string',
                'defaultTimezone' => 'required|string',
                'emailVerification' => 'boolean',
                'landingPageEnabled' => 'boolean',
                'ipRestrictionEnabled'  => 'boolean',
            ];

            if(isSaaS()){
                $rules['termsConditionsUrl'] = 'nullable';
                $rules['userRegistrationEnabled'] = 'boolean';
            } else {
                $rules['termsConditionsUrl'] = 'nullable';
            }

            $validated = $request->validate($rules);

            foreach ($validated as $key => $value) {
                updateSetting($key, $value);
            }

            \App\Models\AuditLog::record('system', 'system_change', 'System Settings', 'System settings updated (' . implode(', ', array_keys($validated)) . ')');

            return redirect()->back()->with('success', __('System settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update system settings: :error', ['error' => $e->getMessage()]));
        }
    }
    
    /**
     * Update the brand settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateBrand(Request $request)
    {
        try {
            // Resolve the settings owner the SAME way settings()/getSetting reads
            // them, so brand assets are scoped per COMPANY (not per logged-in
            // sub-user). Without this, an HR/admin sub-user uploading a logo would
            // write it under their own id and it would never be read back.
            $authUser = auth()->user();
            if (isSaas()) {
                $userId = in_array($authUser->type, ['superadmin', 'company'])
                    ? $authUser->id
                    : (getCompanyId($authUser->created_by) ?? $authUser->id);
            } else {
                $userId = $authUser->type === 'company'
                    ? $authUser->id
                    : (getCompanyId($authUser->id) ?? $authUser->id);
            }

            // Ensure the logo storage directory exists
            Storage::disk('public')->makeDirectory('media/logo');

            // Handle logo file uploads (logoDarkFile, logoLightFile, faviconFile)
            $logoUploadMap = [
                'logoDark'  => 'logoDarkFile',
                'logoLight' => 'logoLightFile',
                'favicon'   => 'faviconFile',
            ];

            $fileUploaded = [];
            foreach ($logoUploadMap as $settingKey => $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);

                    // Validate image type
                    $request->validate([
                        $fileKey => 'file|mimes:jpeg,jpg,png,gif,svg,webp,ico|max:5120',
                    ]);

                    // Delete old logo if stored in our managed directory
                    $oldPath = getSetting($settingKey, null, $userId);
                    if ($oldPath && str_contains($oldPath, 'media/logo/')) {
                        $diskRelativePath = preg_replace('#^storage/#', '', $oldPath);
                        Storage::disk('public')->delete($diskRelativePath);
                    }

                    // Store new logo
                    $filename  = $settingKey . '_' . random_uploaded_file_name($file);
                    $storedPath = $file->storeAs('media/logo', $filename, 'public');

                    // Save as 'storage/media/logo/filename.ext' so getImagePath resolves correctly
                    updateSetting($settingKey, 'storage/' . $storedPath, $userId);
                    $fileUploaded[] = $settingKey;
                }
            }

            // Handle remaining text/theme settings
            $validated = $request->validate([
                'settings'                => 'sometimes|array',
                'settings.logoDark'       => 'nullable|string',
                'settings.logoLight'      => 'nullable|string',
                'settings.favicon'        => 'nullable|string',
                'settings.titleText'      => 'nullable|string|max:255',
                'settings.footerText'     => 'nullable|string|max:500',
                'settings.companyMobile'  => 'nullable|string|max:20',
                'settings.companyEmail'   => 'nullable|string|email|max:255',
                'settings.companyAddress' => 'nullable|string',
                'settings.themeColor'     => 'nullable|string|in:blue,green,purple,orange,red,custom',
                'settings.customColor'    => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'settings.sidebarVariant' => 'nullable|string|in:inset,floating,minimal',
                'settings.sidebarStyle'   => 'nullable|string|in:plain,colored,gradient',
                'settings.layoutDirection'=> 'nullable|string|in:left,right',
                'settings.themeMode'      => 'nullable|string|in:light,dark,system',
            ]);

            if (!empty($validated['settings'])) {
                foreach ($validated['settings'] as $key => $value) {
                    // Skip logo fields that were already saved via file upload
                    if (in_array($key, $fileUploaded)) {
                        continue;
                    }
                    updateSetting($key, $value, $userId);
                }
            }

            return redirect()->back()->with('success', __('Brand settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update brand settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the per-company employee-ID format (prefix + zero-padding).
     */
    public function updateEmployeeIdSettings(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id_prefix'  => 'nullable|string|max:10',
                'employee_id_padding' => 'required|integer|min:1|max:12',
            ]);

            // Scope to the COMPANY the same way settings()/getSetting reads them.
            $authUser = auth()->user();
            if (isSaas()) {
                $userId = in_array($authUser->type, ['superadmin', 'company'])
                    ? $authUser->id
                    : (getCompanyId($authUser->created_by) ?? $authUser->id);
            } else {
                $userId = $authUser->type === 'company'
                    ? $authUser->id
                    : (getCompanyId($authUser->id) ?? $authUser->id);
            }

            updateSetting('employee_id_prefix', trim((string) ($validated['employee_id_prefix'] ?? 'EMP')), $userId);
            updateSetting('employee_id_padding', (string) $validated['employee_id_padding'], $userId);

            return redirect()->back()->with('success', __('Employee ID settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update employee ID settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the recaptcha settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateRecaptcha(Request $request)
    {
        try {
            $validated = $request->validate([
                'recaptchaEnabled' => 'boolean',
                'recaptchaVersion' => 'required|in:v2,v3',
                'recaptchaSiteKey' => 'required|string',
                'recaptchaSecretKey' => 'required|string',
            ]);
            
            foreach ($validated as $key => $value) {
                updateSetting($key, $value);
            }

            return redirect()->back()->with('success', __('ReCaptcha settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update ReCaptcha settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the chatgpt settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateChatgpt(Request $request)
    {
        try {
            $validated = $request->validate([
                'chatgptKey' => 'required|string',
                'chatgptModel' => 'required|string',
            ]);
            
            foreach ($validated as $key => $value) {
                updateSetting($key, $value);
            }

            return redirect()->back()->with('success', __('Chat GPT settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update Chat GPT settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the storage settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateStorage(Request $request)
    {
        try {
            $validated = $request->validate([
                'storage_type' => 'required|in:local,aws_s3,wasabi',
                'allowedFileTypes' => 'required|string',
                'maxUploadSize' => 'required|numeric|min:1',
                'awsAccessKeyId' => 'required_if:storage_type,aws_s3|string',
                'awsSecretAccessKey' => 'required_if:storage_type,aws_s3|string',
                'awsDefaultRegion' => 'required_if:storage_type,aws_s3|string',
                'awsBucket' => 'required_if:storage_type,aws_s3|string',
                'awsUrl' => 'required_if:storage_type,aws_s3|string',
                'awsEndpoint' => 'required_if:storage_type,aws_s3|string',
                'wasabiAccessKey' => 'required_if:storage_type,wasabi|string',
                'wasabiSecretKey' => 'required_if:storage_type,wasabi|string',
                'wasabiRegion' => 'required_if:storage_type,wasabi|string',
                'wasabiBucket' => 'required_if:storage_type,wasabi|string',
                'wasabiUrl' => 'required_if:storage_type,wasabi|string',
                'wasabiRoot' => 'required_if:storage_type,wasabi|string',
            ]);

            $userId = Auth::id();
            
            $settings = [
                'storage_type' => $validated['storage_type'],
                'storage_file_types' => $validated['allowedFileTypes'],
                'storage_max_upload_size' => $validated['maxUploadSize'],
            ];

            if ($validated['storage_type'] === 'aws_s3') {
                $settings['aws_access_key_id'] = $validated['awsAccessKeyId'];
                $settings['aws_secret_access_key'] = $validated['awsSecretAccessKey'];
                $settings['aws_default_region'] = $validated['awsDefaultRegion'];
                $settings['aws_bucket'] = $validated['awsBucket'];
                $settings['aws_url'] = $validated['awsUrl'];
                $settings['aws_endpoint'] = $validated['awsEndpoint'];
            }

            if ($validated['storage_type'] === 'wasabi') {
                $settings['wasabi_access_key'] = $validated['wasabiAccessKey'];
                $settings['wasabi_secret_key'] = $validated['wasabiSecretKey'];
                $settings['wasabi_region'] = $validated['wasabiRegion'];
                $settings['wasabi_bucket'] = $validated['wasabiBucket'];
                $settings['wasabi_url'] = $validated['wasabiUrl'];
                $settings['wasabi_root'] = $validated['wasabiRoot'];
            }
            
            foreach ($settings as $key => $value) {
                updateSetting($key, $value);
            }

            StorageConfigService::clearCache();

            return redirect()->back()->with('success', __('Storage settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update storage settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the cookie settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateCookie(Request $request)
    {
        try {
            $validated = $request->validate([
                'enableLogging' => 'required|boolean',
                'strictlyNecessaryCookies' => 'required|boolean',
                'cookieTitle' => 'required|string|max:255',
                'strictlyCookieTitle' => 'required|string|max:255',
                'cookieDescription' => 'required|string',
                'strictlyCookieDescription' => 'required|string',
                'contactUsDescription' => 'required|string',
                'contactUsUrl' => 'required|url',
            ]);
            
            foreach ($validated as $key => $value) {
                updateSetting($key, is_bool($value) ? ($value ? '1' : '0') : $value);
            }

            return redirect()->back()->with('success', __('Cookie settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update cookie settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the SEO settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateSeo(Request $request)
    {
        try {
            $validated = $request->validate([
                'metaKeywords' => 'required|string|max:255',
                'metaDescription' => 'required|string|max:160',
                'metaImage' => 'nullable|file|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            ]);

            if ($request->hasFile('metaImage')) {
                $filenameWithExt = $request->file('metaImage')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('metaImage')->getClientOriginalExtension();
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                $upload = upload_file($request, 'metaImage', $fileNameToStore, 'seo');
                if ($upload['status'] == true) {
                    $validated['metaImage'] = $upload['url'];
                } else {
                    return redirect()->back()
                        ->withErrors(['metaImage' => $upload['msg']])
                        ->withInput();
                }
            }
            
            foreach ($validated as $key => $value) {
                updateSetting($key, $value);
            }

            return redirect()->back()->with('success', __('SEO settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update SEO settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the Google Calendar settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateGoogleCalendar(Request $request)
    {
        try {
            $validated = $request->validate([
                'googleCalendarEnabled' => 'boolean',
                'googleCalendarId' => 'nullable|string|max:255',
                'googleCalendarJson' => 'nullable|file|mimes:json|max:2048',
            ]);

            $settings = [
                'googleCalendarEnabled' => $validated['googleCalendarEnabled'] ?? false,
                'googleCalendarId' => $validated['googleCalendarId'] ?? '',
            ];

            // Handle JSON file upload
            if ($request->hasFile('googleCalendarJson')) {
                $file = $request->file('googleCalendarJson');
                $path = $file->store('google-calendar', 'public');
                $settings['googleCalendarJsonPath'] = $path;
            }

            foreach ($settings as $key => $value) {
                updateSetting($key, is_bool($value) ? ($value ? '1' : '0') : $value);
            }

            return redirect()->back()->with('success', __('Google Calendar settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update Google Calendar settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Update the Google Wallet settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateGoogleWallet(Request $request)
    {
        try {
            $validated = $request->validate([
                'googleWalletIssuerId' => 'nullable|string|max:255',
                'googleWalletJson' => 'nullable|file|mimes:json|max:2048',
            ]);

            $settings = [
                'googleWalletIssuerId' => $validated['googleWalletIssuerId'] ?? '',
            ];

            // Handle JSON file upload
            if ($request->hasFile('googleWalletJson')) {
                $file = $request->file('googleWalletJson');
                $path = $file->store('google-wallet', 'public');
                $settings['googleWalletJsonPath'] = $path;
            }

            foreach ($settings as $key => $value) {
                updateSetting($key, $value);
            }

            return redirect()->back()->with('success', __('Google Wallet settings updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to update Google Wallet settings: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Clear application cache.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearCache()
    {
        try {
            \Artisan::call('cache:clear');
            \Artisan::call('route:clear');
            \Artisan::call('view:clear');
            \Artisan::call('optimize:clear');

            return redirect()->back()->with('success', __('Cache cleared successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Failed to clear cache: :error', ['error' => $e->getMessage()]));
        }
    }
}
