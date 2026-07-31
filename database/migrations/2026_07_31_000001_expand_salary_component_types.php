<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_TYPES = "'income', 'benefit', 'deduction_tax_deductible', 'deduction_non_tax', 'company_contribution', 'leave'";

    public function up(): void
    {
        DB::statement(
            "ALTER TABLE salary_components MODIFY COLUMN type ENUM(".
            "'earning', 'deduction', ".self::NEW_TYPES.
            ") NOT NULL DEFAULT 'earning'"
        );

        DB::table('salary_components')
            ->where('type', 'earning')
            ->update(['type' => 'income']);

        DB::table('salary_components')
            ->where('type', 'deduction')
            ->where('calculation_type', 'zambia_pension')
            ->update(['type' => 'deduction_tax_deductible']);

        DB::table('salary_components')
            ->where('type', 'deduction')
            ->update(['type' => 'deduction_non_tax']);

        DB::statement(
            'ALTER TABLE salary_components MODIFY COLUMN type ENUM('.self::NEW_TYPES.
            ") NOT NULL DEFAULT 'income'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE salary_components MODIFY COLUMN type ENUM(".
            "'earning', 'deduction', ".self::NEW_TYPES.
            ") NOT NULL DEFAULT 'income'"
        );

        DB::table('salary_components')
            ->whereIn('type', ['income', 'benefit', 'leave', 'company_contribution'])
            ->update(['type' => 'earning']);

        DB::table('salary_components')
            ->whereIn('type', ['deduction_tax_deductible', 'deduction_non_tax'])
            ->update(['type' => 'deduction']);

        DB::statement(
            "ALTER TABLE salary_components MODIFY COLUMN type " .
            "ENUM('earning', 'deduction') NOT NULL DEFAULT 'earning'"
        );
    }
};
