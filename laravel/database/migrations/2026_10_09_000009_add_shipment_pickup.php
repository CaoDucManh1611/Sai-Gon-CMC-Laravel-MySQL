<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('pickup_by')->nullable()->constrained('users');
            $table->string('pickup_receiver')->nullable();
            $table->timestamp('picked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropConstrainedForeignId('pickup_by'));
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn(['pickup_receiver', 'picked_up_at']));
    }
};
