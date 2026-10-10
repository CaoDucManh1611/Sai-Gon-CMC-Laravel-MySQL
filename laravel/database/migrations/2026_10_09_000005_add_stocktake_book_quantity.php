<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_items', fn (Blueprint $table) => $table->decimal('book_quantity', 18, 3)->nullable());
    }

    public function down(): void
    {
        Schema::table('stock_document_items', fn (Blueprint $table) => $table->dropColumn('book_quantity'));
    }
};
