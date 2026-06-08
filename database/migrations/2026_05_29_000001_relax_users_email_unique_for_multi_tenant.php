<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasTenantDuplicates = DB::table('users')
            ->select('email', 'created_by')
            ->groupBy('email', 'created_by')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasTenantDuplicates) {
            throw new RuntimeException('Cannot add users_email_created_by_unique: duplicate email values already exist inside a tenant.');
        }

        if (Schema::hasIndex('users', 'users_email_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_email_unique');
            });
        }

        if (! Schema::hasIndex('users', 'users_email_created_by_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique(['email', 'created_by'], 'users_email_created_by_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', 'users_email_created_by_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_email_created_by_unique');
            });
        }

        if (! Schema::hasIndex('users', 'users_email_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('email', 'users_email_unique');
            });
        }
    }
};
