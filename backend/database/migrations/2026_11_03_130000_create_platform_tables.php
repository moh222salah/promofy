<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 48);
            $table->string('key', 96);
            $table->longText('value')->nullable();       // JSON, or encrypted JSON for secrets
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->string('symbol_ar', 12);
            $table->string('symbol_en', 12);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->string('phone_code', 8);
            $table->char('currency_code', 3)->nullable();
            $table->string('flag', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_market')->default(false);      // a market the agency actively serves
            $table->json('payment_methods')->nullable();       // market-specific payment availability
            $table->json('contact')->nullable();               // market-specific phone / WhatsApp / address
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Exchange rates are always configured by an administrator (or fetched from an approved source);
        // the platform never assumes a fixed rate.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->string('source', 80)->default('manual');
            $table->timestamp('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['base_currency', 'quote_currency', 'effective_at']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 24)->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->string('extension', 12);
            $table->unsignedBigInteger('size');
            $table->string('kind', 16)->index();                // image | video | document
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('title')->nullable();
            $table->string('alt_ar')->nullable();
            $table->string('alt_en')->nullable();
            $table->string('category', 64)->nullable()->index();
            $table->json('variants')->nullable();               // generated sizes: name => path
            $table->char('checksum', 64)->nullable()->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Where each media item is used, so deletion can be refused safely.
        Schema::create('mediables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('mediable_type', 48);
            $table->unsignedBigInteger('mediable_id');
            $table->string('collection', 48)->default('default');
            $table->unsignedInteger('sort_order')->default(0);

            $table->index(['mediable_type', 'mediable_id', 'collection']);
        });
    }

    public function down(): void
    {
        foreach (['mediables', 'media', 'exchange_rates', 'countries', 'currencies', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
