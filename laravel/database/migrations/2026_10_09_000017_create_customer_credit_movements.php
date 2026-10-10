<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_credit_id')->constrained();
            $table->foreignId('debt_id')->nullable()->constrained();
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('type', 20);
            $table->decimal('amount', 18, 2);
            $table->string('method', 20)->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_movements');
    }
};
