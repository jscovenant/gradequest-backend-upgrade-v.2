<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Store Categories
        if (!Schema::hasTable('store_categories')) {
            Schema::create('store_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Store Items (Uniforms, Books, Crests, Stationery)
        if (!Schema::hasTable('store_items')) {
            Schema::create('store_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->string('name');
                $table->string('item_code')->nullable()->index(); // Barcode or SKU (e.g. UNIFORM-JSS1-M)
                $table->enum('item_type', ['uniform', 'book', 'stationery', 'crest', 'accessory', 'other'])->default('other');
                $table->text('description')->nullable();
                $table->decimal('cost_price', 12, 2)->default(0.00); // Purchase cost for profit analysis
                $table->decimal('selling_price', 12, 2)->default(0.00);
                $table->integer('current_stock')->default(0);
                $table->integer('reorder_level')->default(5); // Low stock alert threshold
                $table->string('unit')->default('pcs'); // pcs, sets, pairs, pack
                $table->string('size')->nullable(); // S, M, L, XL, 32, 34, etc.
                $table->string('class_target')->nullable(); // e.g. JSS 1, Primary 3, All
                $table->string('image_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('category_id')->references('id')->on('store_categories')->onDelete('set null');
            });
        }

        // 3. Store Sales (POS Orders)
        if (!Schema::hasTable('store_sales')) {
            Schema::create('store_sales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->string('receipt_number')->unique();
                $table->unsignedBigInteger('student_id')->nullable()->index(); // If bought for/by specific student (users table)
                $table->string('buyer_name')->nullable(); // Parent/Guardian or Walk-in customer name
                $table->string('buyer_phone')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0.00);
                $table->decimal('discount', 12, 2)->default(0.00);
                $table->decimal('tax', 12, 2)->default(0.00);
                $table->decimal('total_amount', 12, 2)->default(0.00);
                $table->decimal('total_cost', 12, 2)->default(0.00); // For instant profit reporting
                $table->decimal('amount_tendered', 12, 2)->default(0.00);
                $table->decimal('change_due', 12, 2)->default(0.00);
                $table->enum('payment_method', ['cash', 'pos_card', 'bank_transfer', 'wallet', 'split'])->default('cash');
                $table->enum('payment_status', ['paid', 'partial', 'pending', 'refunded'])->default('paid');
                $table->unsignedBigInteger('served_by_user_id')->nullable()->index(); // Staff who processed the POS sale
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 4. Store Sale Items
        if (!Schema::hasTable('store_sale_items')) {
            Schema::create('store_sale_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('sale_id')->index();
                $table->unsignedBigInteger('store_item_id')->nullable()->index();
                $table->string('item_name');
                $table->string('item_code')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_cost', 12, 2)->default(0.00);
                $table->decimal('unit_price', 12, 2)->default(0.00);
                $table->decimal('total_price', 12, 2)->default(0.00);
                $table->decimal('profit', 12, 2)->default(0.00);
                $table->timestamps();

                $table->foreign('sale_id')->references('id')->on('store_sales')->onDelete('cascade');
                $table->foreign('store_item_id')->references('id')->on('store_items')->onDelete('set null');
            });
        }

        // 5. Store Stock History & Logs
        if (!Schema::hasTable('store_stock_logs')) {
            Schema::create('store_stock_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('store_item_id')->index();
                $table->enum('type', ['restock', 'sale', 'adjustment', 'return', 'damaged'])->default('restock');
                $table->integer('quantity_change'); // positive for restock/return, negative for sale/damage
                $table->integer('stock_before');
                $table->integer('stock_after');
                $table->decimal('unit_cost', 12, 2)->nullable();
                $table->string('reference')->nullable(); // Sale Receipt # or Supplier Invoice #
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('performed_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('store_item_id')->references('id')->on('store_items')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_stock_logs');
        Schema::dropIfExists('store_sale_items');
        Schema::dropIfExists('store_sales');
        Schema::dropIfExists('store_items');
        Schema::dropIfExists('store_categories');
    }
};
