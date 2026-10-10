<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', fn (Blueprint $table) => $table->text('settlement_note')->nullable());
    }

    public function down(): void
    {
        Schema::table('debts', fn (Blueprint $table) => $table->dropColumn('settlement_note'));
    }
};
