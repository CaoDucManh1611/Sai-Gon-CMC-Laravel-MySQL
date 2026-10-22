<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->string('filename');
            $table->longText('markdown');
            $table->json('chunks');
            $table->string('status')->default('draft')->index();
            $table->boolean('active')->default(true);
            $table->uuid('index_version')->nullable();
            $table->string('embedding_model')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
