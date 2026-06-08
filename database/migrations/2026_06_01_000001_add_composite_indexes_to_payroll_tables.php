<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            if (! $this->indexExists('payroll_runs', 'payroll_runs_created_by_status_idx')) {
                $table->index(['created_by', 'status'], 'payroll_runs_created_by_status_idx');
            }
            if (! $this->indexExists('payroll_runs', 'payroll_runs_created_by_pay_period_idx')) {
                $table->index(['created_by', 'pay_period_start'], 'payroll_runs_created_by_pay_period_idx');
            }
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            if (! $this->indexExists('payroll_entries', 'payroll_entries_created_by_idx')) {
                $table->index('created_by', 'payroll_entries_created_by_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            if ($this->indexExists('payroll_runs', 'payroll_runs_created_by_status_idx')) {
                $table->dropIndex('payroll_runs_created_by_status_idx');
            }
            if ($this->indexExists('payroll_runs', 'payroll_runs_created_by_pay_period_idx')) {
                $table->dropIndex('payroll_runs_created_by_pay_period_idx');
            }
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            if ($this->indexExists('payroll_entries', 'payroll_entries_created_by_idx')) {
                $table->dropIndex('payroll_entries_created_by_idx');
            }
        });
    }

    /**
     * Database-agnostic index existence check so the migration is safe to run
     * on SQLite (local dev), MySQL (production), and PostgreSQL.
     */
    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('tbl_name', $table)
                ->where('name', $index)
                ->exists(),
            'mysql', 'mariadb' => collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty(),
            'pgsql' => DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $index)
                ->exists(),
            default => false,
        };
    }
};
