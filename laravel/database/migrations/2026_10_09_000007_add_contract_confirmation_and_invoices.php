<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->text('customer_note')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('issued_by')->constrained('users');
            $table->decimal('total', 18, 2);
            $table->decimal('paid_at_issue', 18, 2);
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn(['customer_confirmed_at', 'customer_note']);
        });
    }
};
