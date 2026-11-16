<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('phone', 50)->default('');
            $table->text('notes');
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('sku', 64)->nullable()->unique();
            $table->boolean('active')->default(true);
        });
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('unit', 32);
            $table->unsignedInteger('price');
            $table->unsignedInteger('stock')->nullable();
            $table->unique(['product_id', 'unit']);
        });
        Schema::create('customer_prices', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('product_unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->unsignedInteger('price');
            $table->primary(['customer_id', 'product_unit_id']);
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('customer_name', 150);
            $table->string('customer_phone', 50);
            $table->string('status', 16)->default('open')->index();
            $table->text('notes');
            $table->unsignedBigInteger('total');
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('product_id')->constrained();
            $table->string('product_name', 150);
            $table->string('unit', 32);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedBigInteger('line_total');
            $table->primary(['order_id', 'position']);
            $table->index(['product_id', 'unit']);
        });
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('action', 16);
            $table->dateTime('happened_at');
        });
    }

    public function down(): void
    {
        foreach (['order_events', 'order_items', 'orders', 'customer_prices', 'product_units', 'products', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
