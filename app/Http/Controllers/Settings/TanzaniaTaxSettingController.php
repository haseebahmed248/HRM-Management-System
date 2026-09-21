<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Tanzania tax settings admin, mirrors ZambiaTaxSettingController.
 *
 * Settings live under the Super Admin scope so all companies inherit one
 * global set. When TRA changes rates (annual budget, etc.) the Super Admin
 * updates them here and every Tanzania company picks up the change on the
 * next payroll run without needing per-tenant edits.
 */
class TanzaniaTaxSettingController extends Controller
{
    public function update(Request $request)
    {
        if (Auth::user()->type !== 'superadmin') {
            return redirect()->back()->with(
                'error',
                __('Only the Super Admin can change Tanzania tax settings.')
            );
        }

        $validator = Validator::make($request->all(), [
            // PAYE bands (5 slabs matches the 2026 TRA structure).
            'tanzania_paye_slab_1_min'  => 'required|numeric|min:0',
            'tanzania_paye_slab_1_max'  => 'required|numeric|min:0',
            'tanzania_paye_slab_1_rate' => 'required|numeric|min:0|max:100',
            'tanzania_paye_slab_2_min'  => 'required|numeric|min:0',
            'tanzania_paye_slab_2_max'  => 'required|numeric|min:0',
            'tanzania_paye_slab_2_rate' => 'required|numeric|min:0|max:100',
            'tanzania_paye_slab_3_min'  => 'required|numeric|min:0',
            'tanzania_paye_slab_3_max'  => 'required|numeric|min:0',
            'tanzania_paye_slab_3_rate' => 'required|numeric|min:0|max:100',
            'tanzania_paye_slab_4_min'  => 'required|numeric|min:0',
            'tanzania_paye_slab_4_max'  => 'required|numeric|min:0',
            'tanzania_paye_slab_4_rate' => 'required|numeric|min:0|max:100',
            'tanzania_paye_slab_5_min'  => 'required|numeric|min:0',
            'tanzania_paye_slab_5_rate' => 'required|numeric|min:0|max:100',
            // NSSF (no ceiling — verify at TRA if this changes).
            'tanzania_nssf_employee_rate' => 'required|numeric|min:0|max:100',
            'tanzania_nssf_employer_rate' => 'required|numeric|min:0|max:100',
            // SDL — rate + head-count threshold at which the levy kicks in.
            'tanzania_sdl_rate'                => 'required|numeric|min:0|max:100',
            'tanzania_sdl_employee_threshold'  => 'required|integer|min:0',
            // WCF (unified 0.5%).
            'tanzania_wcf_rate' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $userId = getSuperAdminId() ?? creatorId();

        $keys = [
            'tanzania_paye_slab_1_min', 'tanzania_paye_slab_1_max', 'tanzania_paye_slab_1_rate',
            'tanzania_paye_slab_2_min', 'tanzania_paye_slab_2_max', 'tanzania_paye_slab_2_rate',
            'tanzania_paye_slab_3_min', 'tanzania_paye_slab_3_max', 'tanzania_paye_slab_3_rate',
            'tanzania_paye_slab_4_min', 'tanzania_paye_slab_4_max', 'tanzania_paye_slab_4_rate',
            'tanzania_paye_slab_5_min', 'tanzania_paye_slab_5_rate',
            'tanzania_nssf_employee_rate', 'tanzania_nssf_employer_rate',
            'tanzania_sdl_rate', 'tanzania_sdl_employee_threshold',
            'tanzania_wcf_rate',
        ];

        foreach ($keys as $key) {
            Setting::updateOrCreate(
                ['user_id' => $userId, 'key' => $key],
                ['value'   => $request->input($key)]
            );
        }

        \App\Models\AuditLog::record(
            'system',
            'system_change',
            'Tanzania Tax Settings',
            'Tanzania tax settings updated (PAYE bands / NSSF / SDL / WCF).'
        );

        return redirect()->back()->with('success', __('Tanzania tax settings updated successfully.'));
    }
}
