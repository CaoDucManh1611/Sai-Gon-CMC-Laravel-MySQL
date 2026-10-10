<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('code', 30)->unique();
            $t->string('company')->nullable();
            $t->string('tax_code', 30)->nullable();
            $t->decimal('credit_limit', 18, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('unit', 30);
            $t->string('specification')->nullable();
            $t->string('manufacturer')->nullable();
            $t->decimal('price', 18, 2)->default(0);
            $t->decimal('weight_kg', 12, 3)->nullable();
            $t->decimal('minimum_stock', 18, 3)->default(0);
            $t->string('image_path')->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('price_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->decimal('price', 18, 2);
            $t->date('effective_date');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('catalog_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->nullable()->constrained();
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->string('type', 30);
            $t->json('proposed_data');
            $t->string('status', 20)->default('pending');
            $t->text('review_note')->nullable();
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('tax_code', 30)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('contact_person')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('inventory', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 18, 3)->default(0);
            $t->unique(['warehouse_id', 'product_id']);
            $t->timestamps();
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('customer_id')->constrained('users');
            $t->foreignId('salesperson_id')->nullable()->constrained('users');
            $t->string('status', 30)->default('requested');
            $t->text('delivery_address');
            $t->text('customer_note')->nullable();
            $t->text('staff_note')->nullable();
            $t->decimal('total', 18, 2)->default(0);
            $t->date('valid_until')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('quote_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('line_total', 18, 2);
            $t->string('specification')->nullable();
            $t->timestamps();
        });
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('quote_id')->unique()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('status', 30)->default('draft');
            $t->decimal('total', 18, 2);
            $t->decimal('deposit_required', 18, 2)->default(0);
            $t->text('payment_terms')->nullable();
            $t->text('delivery_terms')->nullable();
            $t->text('approval_note')->nullable();
            $t->string('document_path')->nullable();
            $t->date('effective_date')->nullable();
            $t->date('expires_on')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('contract_id')->unique()->constrained();
            $t->foreignId('customer_id')->constrained('users');
            $t->string('status', 30)->default('awaiting_payment');
            $t->decimal('total', 18, 2);
            $t->decimal('paid', 18, 2)->default(0);
            $t->text('delivery_address');
            $t->dateTime('planned_delivery_at')->nullable();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('line_total', 18, 2);
            $t->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('status', 30)->default('pending');
            $t->decimal('total', 18, 2)->default(0);
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('received_quantity', 18, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('type', 20);
            $t->foreignId('warehouse_id')->constrained();
            $t->foreignId('order_id')->nullable()->constrained();
            $t->foreignId('purchase_order_id')->nullable()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('status', 20)->default('draft');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_document_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_price', 18, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_document_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('change', 18, 3);
            $t->decimal('balance_after', 18, 3);
            $t->timestamps();
        });
        Schema::create('debts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('direction', 20);
            $t->foreignId('customer_id')->nullable()->constrained('users');
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->foreignId('order_id')->nullable()->constrained();
            $t->foreignId('purchase_order_id')->nullable()->constrained();
            $t->decimal('amount', 18, 2);
            $t->decimal('paid', 18, 2)->default(0);
            $t->date('due_on')->nullable();
            $t->string('status', 20)->default('open');
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('debt_id')->constrained();
            $t->foreignId('recorded_by')->constrained('users');
            $t->decimal('amount', 18, 2);
            $t->string('method', 20);
            $t->string('reference')->nullable();
            $t->dateTime('paid_at');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('carrier_id')->nullable()->constrained('users');
            $t->string('carrier_name')->nullable();
            $t->string('vehicle')->nullable();
            $t->text('route_note')->nullable();
            $t->string('status', 30)->default('planned');
            $t->dateTime('planned_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('handovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->unique()->constrained();
            $t->string('recipient');
            $t->string('recipient_phone', 20)->nullable();
            $t->text('condition_note')->nullable();
            $t->string('document_path')->nullable();
            $t->boolean('accepted')->default(false);
            $t->dateTime('signed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('returns', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('customer_id')->constrained('users');
            $t->foreignId('processed_by')->nullable()->constrained('users');
            $t->decimal('quantity', 18, 3);
            $t->decimal('credit_amount', 18, 2)->default(0);
            $t->string('type', 20);
            $t->string('condition', 20)->nullable();
            $t->string('status', 30)->default('requested');
            $t->text('reason');
            $t->text('inspection_note')->nullable();
            $t->timestamps();
        });
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('type', 30);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->date('from_date');
            $t->date('to_date');
            $t->json('snapshot')->nullable();
            $t->string('status', 20)->default('draft');
            $t->text('review_note')->nullable();
            $t->timestamps();
        });
        Schema::create('estimation_norms', function (Blueprint $t) {
            $t->id();
            $t->string('building_type', 50);
            $t->foreignId('product_id')->constrained();
            $t->decimal('quantity_per_square_meter', 18, 4);
            $t->string('source')->nullable();
            $t->unique(['building_type', 'product_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['estimation_norms', 'reports', 'returns', 'handovers', 'shipments', 'payments', 'debts',
            'stock_movements', 'stock_document_items', 'stock_documents', 'purchase_items', 'purchase_orders',
            'order_items', 'orders', 'contracts', 'quote_items', 'quotes', 'inventory', 'warehouses', 'suppliers',
            'catalog_changes', 'price_history', 'products', 'categories', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
