<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;
use App\Models\User;

/**
 * Tanzania module (Sep 2026):
 *
 * 1. Adds a `country_code` column to `users` so each company can be tagged
 *    ZM (default) or TZ. Payroll routing keys off this column.
 * 2. Seeds Tanzania default tax settings against the Super Admin scope so
 *    every Tanzania company inherits current TRA rates out of the box.
 *
 * The Zambia settings are untouched — existing companies stay on Zambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. country_code on the users table (companies).
        if (! Schema::hasColumn('users', 'country_code')) {
            Schema::table('users', function (Blueprint $table) {
                // 2-letter ISO country code. Default 'ZM' preserves existing
                // Zambia behaviour; new Tanzania companies get 'TZ'.
                $table->string('country_code', 2)->nullable()->default('ZM')->after('type');
            });
        }

        // 2. Tanzania default tax settings (2026 TRA reference values).
        $defaults = [
            // PAYE bands. Same 5-slab structure as the TRA calculator.
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
            // NSSF: 10% + 10%, no salary ceiling.
            'tanzania_nssf_employee_rate' => '10',
            'tanzania_nssf_employer_rate' => '10',
            // SDL: 3.5% employer, gated at 10+ employees.
            'tanzania_sdl_rate'                => '3.5',
            'tanzania_sdl_employee_threshold'  => '10',
            // WCF: 0.5% employer, unified.
            'tanzania_wcf_rate' => '0.5',
        ];

        // Seed under both the Super Admin (global) and each existing company
        // scope. The service reads from Super Admin first, so the per-company
        // seed is only a safety net if Super Admin is later removed.
        $superAdmin = User::where('type', 'superadmin')->first();
        $scopes = collect();
        if ($superAdmin) {
            $scopes->push($superAdmin);
        }
        $scopes = $scopes->merge(User::where('type', 'company')->get());

        foreach ($scopes as $scopeUser) {
            foreach ($defaults as $key => $value) {
                Setting::firstOrCreate(
                    ['user_id' => $scopeUser->id, 'key' => $key],
                    ['value'   => $value]
                );
            }
        }
    }

    public function down(): void
    {
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
        Setting::whereIn('key', $keys)->delete();

        if (Schema::hasColumn('users', 'country_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('country_code');
            });
        }
    }
};
