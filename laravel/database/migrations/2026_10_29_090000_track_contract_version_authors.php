<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreignId('last_edited_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('last_submitted_by')->nullable()->after('last_edited_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_submitted_by');
            $table->dropConstrainedForeignId('last_edited_by');
        });
    }
};
