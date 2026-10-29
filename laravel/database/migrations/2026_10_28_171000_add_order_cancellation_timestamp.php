<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'cancelled_at')) {
            Schema::table('orders', fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'cancelled_at')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
        }
    }
};
