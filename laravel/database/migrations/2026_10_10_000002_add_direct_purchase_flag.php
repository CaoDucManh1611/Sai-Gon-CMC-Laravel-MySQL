<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->boolean('direct_purchase_allowed')->default(false));
        DB::table('products')->where('code', '!=', 'THEP-KG')->update(['direct_purchase_allowed' => true]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('direct_purchase_allowed'));
    }
};
