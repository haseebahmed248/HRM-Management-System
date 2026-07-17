<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Zambia tax settings are now controlled globally by the Super Admin. Seed the
     * Super Admin's settings so payroll keeps producing the same numbers after the
     * read-scope switch: prefer the values from whichever company already has the
     * most complete set, otherwise fall back to canonical ZRA defaults.
     */
    public function up(): void
    {
        $superAdmin = DB::table('users')->where('type', 'superadmin')->first();
        if (!$superAdmin) {
            return;
        }

        // Already seeded? Do nothing.
        $existing = DB::table('settings')
            ->where('user_id', $superAdmin->id)
            ->where('key', 'like', 'zambia_%')
            ->count();
        if ($existing > 0) {
            return;
        }

        // Canonical ZRA defaults (fallback).
        $values = [
            'zambia_paye_slab_1_min'  => '0',
            'zambia_paye_slab_1_max'  => '5100',
            'zambia_paye_slab_1_rate' => '0',
            'zambia_paye_slab_2_min'  => '5101',
            'zambia_paye_slab_2_max'  => '7100',
            'zambia_paye_slab_2_rate' => '20',
            'zambia_paye_slab_3_min'  => '7101',
            'zambia_paye_slab_3_max'  => '9200',
            'zambia_paye_slab_3_rate' => '30',
            'zambia_paye_slab_4_min'  => '9201',
            'zambia_paye_slab_4_max'  => '999999999',
            'zambia_paye_slab_4_rate' => '37',
            'zambia_napsa_employee_rate' => '5',
            'zambia_napsa_employer_rate' => '5',
            'zambia_napsa_monthly_cap'   => '1073.20',
            'zambia_nhima_employee_rate' => '1',
            'zambia_nhima_employer_rate' => '1',
            'zambia_sdl_rate'            => '0.5',
            'zambia_pension_relief_cap'  => '1000',
        ];

        // Prefer an existing company's configured values (to preserve current payroll).
        $sourceUserId = DB::table('settings')
            ->select('user_id')
            ->where('key', 'like', 'zambia_%')
            ->where('user_id', '!=', $superAdmin->id)
            ->groupBy('user_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('user_id');

        if ($sourceUserId) {
            $sourceValues = DB::table('settings')
                ->where('user_id', $sourceUserId)
                ->where('key', 'like', 'zambia_%')
                ->pluck('value', 'key')
                ->toArray();
            // Overlay the source values onto the defaults (source wins where present).
            $values = array_merge($values, $sourceValues);
        }

        $now = now();
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = [
                'user_id'    => $superAdmin->id,
                'key'        => $key,
                'value'      => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('settings')->insert($rows);
    }

    public function down(): void
    {
        $superAdmin = DB::table('users')->where('type', 'superadmin')->first();
        if ($superAdmin) {
            DB::table('settings')
                ->where('user_id', $superAdmin->id)
                ->where('key', 'like', 'zambia_%')
                ->delete();
        }
    }
};
