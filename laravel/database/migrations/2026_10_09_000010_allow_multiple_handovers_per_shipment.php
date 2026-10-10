<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('handovers', 'handovers_shipment_id_index')) {
            Schema::table('handovers', fn (Blueprint $table) => $table->index('shipment_id', 'handovers_shipment_id_index'));
        }
        Schema::table('handovers', fn (Blueprint $table) => $table->dropUnique('handovers_shipment_id_unique'));
    }

    public function down(): void
    {
        Schema::table('handovers', fn (Blueprint $table) => $table->unique('shipment_id'));
        Schema::table('handovers', fn (Blueprint $table) => $table->dropIndex('handovers_shipment_id_index'));
    }
};
