<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->change();
            $table->string('fulfillment_method', 20)->default('delivery');
            $table->foreignId('pickup_warehouse_id')->nullable()->constrained('warehouses');
            $table->string('pickup_recipient')->nullable();
            $table->foreignId('picked_up_by')->nullable()->constrained('users');
            $table->timestamp('picked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pickup_warehouse_id']);
            $table->dropForeign(['picked_up_by']);
            $table->dropColumn(['fulfillment_method', 'pickup_warehouse_id', 'pickup_recipient', 'picked_up_by', 'picked_up_at']);
        });
        // Existing pickup orders have no contract; restoring NOT NULL requires removing them first.
    }
};
