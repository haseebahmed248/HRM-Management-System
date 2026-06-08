<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasTenantDuplicates = DB::table('employees')
            ->select('employee_id', 'created_by')
            ->groupBy('employee_id', 'created_by')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasTenantDuplicates) {
            throw new RuntimeException('Cannot add employees_employee_id_created_by_unique: duplicate employee_id values already exist inside a tenant.');
        }

        if (Schema::hasIndex('employees', 'employees_employee_id_unique')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique('employees_employee_id_unique');
            });
        }

        if (! Schema::hasIndex('employees', 'employees_employee_id_created_by_unique')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique(['employee_id', 'created_by'], 'employees_employee_id_created_by_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('employees', 'employees_employee_id_created_by_unique')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique('employees_employee_id_created_by_unique');
            });
        }

        if (! Schema::hasIndex('employees', 'employees_employee_id_unique')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique('employee_id', 'employees_employee_id_unique');
            });
        }
    }
};
