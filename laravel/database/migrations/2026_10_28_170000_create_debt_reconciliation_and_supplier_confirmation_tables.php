<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_id')->nullable()->constrained('debt_reconciliations')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->date('period_start');
            $table->date('as_of_date');
            $table->decimal('opening_balance', 18, 2);
            $table->decimal('period_charges', 18, 2);
            $table->decimal('period_returns', 18, 2);
            $table->decimal('period_collected', 18, 2);
            $table->decimal('period_credit_issued', 18, 2);
            $table->decimal('period_offsets', 18, 2);
            $table->decimal('period_cancellations', 18, 2);
            $table->decimal('closing_balance', 18, 2);
            $table->longText('content_snapshot');
            $table->char('snapshot_hash', 64);
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'as_of_date', 'id']);
            $table->unique(['customer_id', 'version_number']);
            $table->index(['status', 'as_of_date']);
        });

        Schema::create('debt_reconciliation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('debt_reconciliations')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->text('content')->nullable();
            $table->timestamp('created_at');
            $table->index(['reconciliation_id', 'id']);
        });

        Schema::create('supplier_debt_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('contact_name', 150);
            $table->date('confirmed_on');
            $table->text('statement');
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('file_mime', 100);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
            $table->index(['debt_id', 'confirmed_on']);
            $table->index(['supplier_id', 'confirmed_on']);
        });

        app(RolePermissionSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debt_confirmations');
        Schema::dropIfExists('debt_reconciliation_messages');
        Schema::dropIfExists('debt_reconciliations');
        $permissionId = DB::table('permissions')->where('code', 'supplier.confirmations.manage')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
