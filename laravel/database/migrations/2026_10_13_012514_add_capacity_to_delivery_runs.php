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
        Schema::table('delivery_runs', function (Blueprint $table) {
            $table->decimal('capacity_kg', 12, 3)->nullable();
            $table->decimal('load_kg', 12, 3)->nullable();
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedInteger('stop_sequence')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('stop_sequence');
        });
        Schema::table('delivery_runs', function (Blueprint $table) {
            $table->dropColumn(['capacity_kg', 'load_kg']);
        });
    }
};
