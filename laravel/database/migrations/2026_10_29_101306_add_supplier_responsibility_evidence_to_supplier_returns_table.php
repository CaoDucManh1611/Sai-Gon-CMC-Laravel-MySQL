<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table): void {
            $table->foreignId('responsibility_confirmed_by')->nullable()->constrained('users');
            $table->timestamp('responsibility_confirmed_at')->nullable();
            $table->text('responsibility_evidence')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsibility_confirmed_by');
            $table->dropColumn(['responsibility_confirmed_at', 'responsibility_evidence']);
        });
    }
};
