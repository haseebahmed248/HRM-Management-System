<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'exempt_from_paye')) {
                $table->boolean('exempt_from_paye')->default(false)->after('exempt_from_sdl');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'exempt_from_paye')) {
                $table->dropColumn('exempt_from_paye');
            }
        });
    }
};
