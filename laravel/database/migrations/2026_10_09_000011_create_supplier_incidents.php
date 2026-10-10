<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('purchase_item_id')->constrained();
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->string('type', 20);
            $table->decimal('quantity', 18, 3);
            $table->text('note');
            $table->text('resolution_note')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_incidents');
    }
};
