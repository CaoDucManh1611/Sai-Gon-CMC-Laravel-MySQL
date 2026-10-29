<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pendingStatuses = ['awaiting_confirmation', 'awaiting_contract', 'awaiting_payment', 'ready_to_issue'];

        DB::table('orders')
            ->whereIn('status', $pendingStatuses)
            ->where(function ($query) {
                $query->whereNull('fulfillment_method')->orWhere('fulfillment_method', '!=', 'pickup');
            })
            ->update(['requires_contract' => true]);

        DB::table('orders')->where('fulfillment_method', 'pickup')
            ->where('status', 'awaiting_contract')->update(['requires_contract' => true]);

        DB::table('orders')->whereNotNull('contract_id')->update(['requires_contract' => true]);

        DB::table('orders')
            ->whereIn('id', DB::table('contracts')->whereNotNull('order_id')->select('order_id'))
            ->update(['requires_contract' => true]);
    }

    public function down(): void
    {
        // This is a data correction. Clearing the flag would erase requirements from legitimate legacy contracts.
    }
};
