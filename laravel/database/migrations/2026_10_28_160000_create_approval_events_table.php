<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_events', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('decision', 30);
            $table->string('status_from', 30);
            $table->string('status_to', 30);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->char('content_hash', 64);
            $table->longText('content_snapshot');
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'decided_at'], 'approval_events_subject_decided_index');
            $table->index(['actor_id', 'decided_at'], 'approval_events_actor_decided_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_events');
    }
};
