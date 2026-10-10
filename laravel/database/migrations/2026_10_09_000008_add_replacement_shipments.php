<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->foreignId('return_id')->nullable()->constrained('returns'));
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropConstrainedForeignId('return_id'));
    }
};
