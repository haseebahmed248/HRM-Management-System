<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 8 - Audit Trail. A general, company-scoped audit log that records
     * employee changes, employee deletions, and system (settings) changes, with
     * who did it, when, and what changed.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category');                 // employee | employee_deleted | system
            $table->string('event');                    // created | updated | deleted | system_change
            $table->string('subject')->nullable();      // e.g. employee name, or "System Settings"
            $table->text('description')->nullable();     // human-readable summary of the change
            $table->json('changes')->nullable();         // old -> new field values
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('performed_by_name')->nullable();
            $table->unsignedBigInteger('created_by');    // company scope
            $table->timestamps();

            $table->index('created_by');
            $table->index('category');
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
