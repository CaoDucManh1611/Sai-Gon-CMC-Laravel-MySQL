<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_credit_movements', 'idempotency_key')) {
            Schema::table('customer_credit_movements', fn (Blueprint $table) => $table->string('idempotency_key', 36)->nullable());
        }

        if (! Schema::hasIndex('customer_credit_movements', ['idempotency_key'], 'unique')) {
            Schema::table('customer_credit_movements', fn (Blueprint $table) => $table->unique('idempotency_key'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('customer_credit_movements', ['idempotency_key'], 'unique')) {
            Schema::table('customer_credit_movements', fn (Blueprint $table) => $table->dropUnique(['idempotency_key']));
        }
        if (Schema::hasColumn('customer_credit_movements', 'idempotency_key')) {
            Schema::table('customer_credit_movements', fn (Blueprint $table) => $table->dropColumn('idempotency_key'));
        }
    }
};
