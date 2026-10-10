<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->foreignId('debt_id')->nullable()->constrained('debts');
            $table->foreignId('settled_by')->nullable()->constrained('users');
            $table->decimal('credit_amount', 18, 2)->nullable();
            $table->text('settlement_note')->nullable();
            $table->timestamp('settled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('debt_id');
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['credit_amount', 'settlement_note', 'settled_at']);
        });
    }
};
