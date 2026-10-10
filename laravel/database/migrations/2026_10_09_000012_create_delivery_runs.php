<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('carrier_name');
            $table->string('vehicle');
            $table->text('route_note');
            $table->timestamps();
        });
        Schema::table('shipments', fn (Blueprint $table) => $table->foreignId('delivery_run_id')->nullable()->constrained());
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_run_id'));
        Schema::dropIfExists('delivery_runs');
    }
};
