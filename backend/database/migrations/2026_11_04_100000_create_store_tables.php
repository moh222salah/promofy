<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('type', 24)->default('digital_download'); // digital_download|template|creative_package|fixed_package|subscription|custom
            $table->string('sku', 64)->unique();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('short_description_ar')->nullable();
            $table->text('short_description_en')->nullable();
            $table->longText('description_ar')->nullable();
            $table->longText('description_en')->nullable();
            $table->decimal('price', 14, 2);
            $table->decimal('discount_price', 14, 2)->nullable();
            $table->char('currency_code', 3);
            $table->json('options')->nullable();                 // option definitions shown to buyers [{name_ar,name_en,values:[…]}]
            $table->boolean('has_variants')->default(false);
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('allow_backorder')->default(false);
            $table->boolean('allow_online_payment')->default(true);
            $table->boolean('allow_whatsapp_order')->default(true);
            $table->string('subscription_interval', 12)->nullable();   // monthly|yearly (subscriptions only)
            $table->string('video_url')->nullable();
            $table->string('seo_title_ar')->nullable();
            $table->string('seo_title_en')->nullable();
            $table->string('seo_description_ar', 400)->nullable();
            $table->string('seo_description_en', 400)->nullable();
            $table->boolean('seo_noindex')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('option_values')->nullable();
            $table->decimal('price', 14, 2)->nullable();          // null = product price
            $table->decimal('discount_price', 14, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->integer('change');
            $table->integer('quantity_after');
            $table->string('reason', 24);                         // adjustment|restock|order|cancellation|refund|initial
            $table->string('reference_type', 24)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 24)->default('local');         // private storage, never publicly URL-addressable
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->char('token', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency_code', 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->json('selected_options')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('method', 16);                          // online | whatsapp
            $table->string('status', 24)->default('pending')->index();
            $table->char('currency_code', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->text('customer_notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('attribution')->nullable();
            $table->char('lookup_token', 40)->unique();            // lets guests view / track their own order
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_email']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku', 64);
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('options')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 14, 2);
            $table->boolean('is_digital')->default(false);
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);                        // gateway key, or "manual"
            $table->string('provider_reference')->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('pending')->index(); // pending|succeeded|failed|refunded|partially_refunded
            $table->decimal('refunded_total', 14, 2)->default(0);
            $table->timestamp('verified_at')->nullable();          // set only from a verified provider callback or an audited manual record
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // Every webhook delivery is stored once; (provider,event_id) uniqueness makes handling idempotent.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 128);
            $table->boolean('signature_valid');
            $table->json('payload')->nullable();
            $table->string('outcome', 24)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('download_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_file_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['download_tokens', 'payment_events', 'payment_refunds', 'payments', 'order_status_history', 'order_notes', 'order_items', 'orders', 'cart_items', 'carts', 'product_files', 'inventory_movements', 'product_variants', 'products', 'product_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
