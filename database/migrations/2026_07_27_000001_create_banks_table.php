<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee bank names were previously a hardcoded ZAMBIAN_BANKS array in the
     * React forms. This makes them a managed, per-company list. We seed the exact
     * former defaults into every existing company so no dropdown loses its options,
     * and employees keep their string `bank_name` untouched.
     */
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Unique per company (not globally) so tenants can keep their own lists.
            $table->unique(['created_by', 'name']);
        });

        $defaults = [
            'Zanaco (Zambia National Commercial Bank)',
            'FNB Zambia (First National Bank)',
            'Stanbic Bank Zambia',
            'Absa Bank Zambia',
            'Atlas Mara Bank Zambia',
            'Citibank Zambia',
            'Bank of China Zambia',
            'Indo Zambia Bank',
            'United Bank for Africa (UBA) Zambia',
            'Access Bank Zambia',
            'First Alliance Bank Zambia',
            'Madison Finance',
            'Investrust Bank Zambia',
            'Development Bank of Zambia',
            'Bank of Zambia',
        ];

        $now = now();

        // Seed the default list for every existing company account.
        $companyIds = DB::table('users')->where('type', 'company')->pluck('id');
        foreach ($companyIds as $companyId) {
            $rows = [];
            foreach ($defaults as $name) {
                $rows[] = [
                    'name'       => $name,
                    'status'     => 'active',
                    'created_by' => $companyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // insertOrIgnore respects the (created_by, name) unique index.
            DB::table('banks')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
