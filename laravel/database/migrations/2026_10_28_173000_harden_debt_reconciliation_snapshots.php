<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('debt_reconciliations', 'period_credit_issued')) {
            Schema::table('debt_reconciliations', fn (Blueprint $table) => $table->decimal('period_credit_issued', 18, 2)->default(0));
        }

        if (! Schema::hasIndex('debt_reconciliations', ['customer_id', 'version_number'], 'unique')) {
            $hasDuplicateVersions = DB::table('debt_reconciliations')->select('customer_id', 'version_number')
                ->groupBy('customer_id', 'version_number')->havingRaw('COUNT(*) > 1')->exists();
            if ($hasDuplicateVersions) {
                throw new RuntimeException('Repair duplicate debt reconciliation versions before applying the unique customer/version index.');
            }
            Schema::table('debt_reconciliations', fn (Blueprint $table) => $table->unique(['customer_id', 'version_number']));
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE debt_reconciliations MODIFY content_snapshot LONGTEXT NOT NULL');
            DB::table('debt_reconciliations')->select('id', 'content_snapshot')->chunkById(50, function ($rows) {
                $rows->each(fn (object $row) => DB::table('debt_reconciliations')->where('id', $row->id)
                    ->update(['snapshot_hash' => hash('sha256', (string) $row->content_snapshot)]));
            });
        }
    }

    public function down(): void
    {
        // This migration hardens schema already owned by the table-creation migration.
    }
};
