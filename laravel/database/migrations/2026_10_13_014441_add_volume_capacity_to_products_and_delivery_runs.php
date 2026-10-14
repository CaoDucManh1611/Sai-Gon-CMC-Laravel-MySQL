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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('volume_m3', 12, 4)->nullable();
        });
        Schema::table('delivery_runs', function (Blueprint $table) {
            $table->decimal('capacity_m3', 12, 4)->nullable();
            $table->decimal('load_m3', 12, 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_runs', function (Blueprint $table) {
            $table->dropColumn(['capacity_m3', 'load_m3']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('volume_m3');
        });
    }
};
