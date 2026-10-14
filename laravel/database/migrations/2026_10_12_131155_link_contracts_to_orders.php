<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->unique()->constrained();
            $table->boolean('requires_contract')->default(false);
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_id')->nullable()->change();
            $table->foreignId('order_id')->nullable()->unique()->constrained();
        });
        foreach (DB::table('orders')->whereNotNull('contract_id')->get(['id', 'contract_id']) as $order) {
            $quoteId = DB::table('contracts')->where('id', $order->contract_id)->value('quote_id');
            DB::table('orders')->where('id', $order->id)->update(['quote_id' => $quoteId]);
            DB::table('contracts')->where('id', $order->contract_id)->update(['order_id' => $order->id]);
        }
    }

    public function down(): void
    {
        if (DB::table('contracts')->whereNull('quote_id')->exists()) {
            throw new RuntimeException('Không thể hoàn tác: hợp đồng mới không có báo giá gốc.');
        }
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->unsignedBigInteger('quote_id')->nullable(false)->change();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn('requires_contract');
        });
    }
};
