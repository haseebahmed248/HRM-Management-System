<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of financial-period changes (item 6: "Maintain an audit trail
     * of period changes"). One row per action: created / updated / closed /
     * reopened / set_current / deleted. Keeps a name snapshot and is scoped by
     * created_by so it survives deletion of the financial year itself.
     */
    public function up(): void
    {
        Schema::create('financial_year_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('financial_year_id')->nullable(); // null if the FY was later deleted
            $table->string('financial_year_name');                        // snapshot
            $table->string('action');                                     // created|updated|closed|reopened|set_current|deleted
            $table->text('description')->nullable();                      // human-readable detail (old -> new)
            $table->unsignedBigInteger('performed_by')->nullable();       // acting user
            $table->string('performed_by_name')->nullable();              // snapshot of who
            $table->unsignedBigInteger('created_by');                     // company scope
            $table->timestamps();

            $table->index('created_by');
            $table->index('financial_year_id');
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_year_audits');
    }
};
