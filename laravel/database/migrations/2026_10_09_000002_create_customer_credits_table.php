<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('users');
            $t->foreignId('return_id')->unique()->constrained('returns');
            $t->decimal('amount', 18, 2);
            $t->string('status', 20)->default('open');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
