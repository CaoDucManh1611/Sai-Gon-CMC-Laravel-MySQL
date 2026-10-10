<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimation_norms', function (Blueprint $table) {
            $table->boolean('per_floor')->default(false);
            $table->string('rounding', 15)->default('whole');
        });
    }

    public function down(): void
    {
        Schema::table('estimation_norms', function (Blueprint $table) {
            $table->dropColumn(['per_floor', 'rounding']);
        });
    }
};
