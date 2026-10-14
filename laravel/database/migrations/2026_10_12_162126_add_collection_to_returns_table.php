<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('collected_by')->nullable()->constrained('users');
            $table->timestamp('collected_at')->nullable();
            $table->string('handed_over_by', 150)->nullable();
            $table->decimal('collected_quantity', 18, 3)->nullable();
            $table->text('collection_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
            $table->dropColumn(['collected_at', 'handed_over_by', 'collected_quantity', 'collection_note']);
        });
    }
};
