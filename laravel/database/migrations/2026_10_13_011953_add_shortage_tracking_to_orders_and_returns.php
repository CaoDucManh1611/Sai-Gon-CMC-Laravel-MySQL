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
        Schema::table('orders', function (Blueprint $table) {
            $table->text('stock_shortage_note')->nullable();
            $table->date('estimated_available_on')->nullable();
        });
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('replacement_warehouse_id')->nullable()->constrained('warehouses');
            $table->foreignId('restock_purchase_order_id')->nullable()->constrained('purchase_orders');
            $table->date('estimated_available_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restock_purchase_order_id');
            $table->dropConstrainedForeignId('replacement_warehouse_id');
            $table->dropColumn('estimated_available_on');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['stock_shortage_note', 'estimated_available_on']);
        });
    }
};
