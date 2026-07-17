<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Names are unique per company (not globally) so different tenants can
            // reuse the same tier names.
            $table->unique(['created_by', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'staff_tier_id')) {
                $table->foreignId('staff_tier_id')->nullable()->after('designation_id')
                    ->constrained('staff_tiers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'staff_tier_id')) {
                $table->dropConstrainedForeignId('staff_tier_id');
            }
        });

        Schema::dropIfExists('staff_tiers');
    }
};
