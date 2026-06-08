<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->relaxUniqueIndex(
            table: 'departments',
            column: 'name',
            globalIndex: 'departments_name_unique',
            tenantIndex: 'departments_name_created_by_unique',
        );

        $this->relaxUniqueIndex(
            table: 'designations',
            column: 'name',
            globalIndex: 'designations_name_unique',
            tenantIndex: 'designations_name_created_by_unique',
        );
    }

    public function down(): void
    {
        $this->restoreGlobalUniqueIndex(
            table: 'designations',
            column: 'name',
            globalIndex: 'designations_name_unique',
            tenantIndex: 'designations_name_created_by_unique',
        );

        $this->restoreGlobalUniqueIndex(
            table: 'departments',
            column: 'name',
            globalIndex: 'departments_name_unique',
            tenantIndex: 'departments_name_created_by_unique',
        );
    }

    private function relaxUniqueIndex(string $table, string $column, string $globalIndex, string $tenantIndex): void
    {
        $hasTenantDuplicates = DB::table($table)
            ->select($column, 'created_by')
            ->groupBy($column, 'created_by')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasTenantDuplicates) {
            throw new RuntimeException("Cannot add {$tenantIndex}: duplicate {$column} values already exist inside a tenant.");
        }

        if (Schema::hasIndex($table, $globalIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($globalIndex) {
                $blueprint->dropUnique($globalIndex);
            });
        }

        if (! Schema::hasIndex($table, $tenantIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $tenantIndex) {
                $blueprint->unique([$column, 'created_by'], $tenantIndex);
            });
        }
    }

    private function restoreGlobalUniqueIndex(string $table, string $column, string $globalIndex, string $tenantIndex): void
    {
        if (Schema::hasIndex($table, $tenantIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($tenantIndex) {
                $blueprint->dropUnique($tenantIndex);
            });
        }

        if (! Schema::hasIndex($table, $globalIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $globalIndex) {
                $blueprint->unique($column, $globalIndex);
            });
        }
    }
};
