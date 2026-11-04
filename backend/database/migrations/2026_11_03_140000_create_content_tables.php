<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Shared columns of every publishable, SEO-aware content record. */
    private function publishing(Blueprint $table): void
    {
        $table->string('seo_title_ar')->nullable();
        $table->string('seo_title_en')->nullable();
        $table->string('seo_description_ar', 400)->nullable();
        $table->string('seo_description_en', 400)->nullable();
        $table->boolean('seo_noindex')->default(false);
        $table->boolean('is_featured')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->string('status', 16)->default('draft')->index();   // draft | published | archived
        $table->timestamp('published_at')->nullable();             // future date = scheduled
    }

    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('icon', 48)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('short_description_ar')->nullable();
            $table->text('short_description_en')->nullable();
            $table->longText('description_ar')->nullable();
            $table->longText('description_en')->nullable();
            $table->json('features')->nullable();        // [{ar, en}]
            $table->json('deliverables')->nullable();    // [{ar, en}]
            $table->json('process_steps')->nullable();   // [{title_ar, title_en, text_ar, text_en}]
            $table->json('faqs')->nullable();            // [{q_ar, q_en, a_ar, a_en}]
            $table->string('duration_ar')->nullable();
            $table->string('duration_en')->nullable();
            // Services are quote-based by default; a price is shown only when an admin configures it.
            $table->string('pricing_type', 16)->default('quote');   // quote | range | fixed
            $table->decimal('price_from', 14, 2)->nullable();
            $table->decimal('price_to', 14, 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('cta_type', 16)->default('quote');       // quote | whatsapp | contact | consultation
            $table->string('cta_label_ar')->nullable();
            $table->string('cta_label_en')->nullable();
            $table->string('video_url')->nullable();
            $this->publishing($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->string('client_name')->nullable();
            $table->boolean('client_approved')->default(false);     // client name is shown only when approved
            $table->text('summary_ar')->nullable();
            $table->text('summary_en')->nullable();
            $table->longText('brief_ar')->nullable();
            $table->longText('brief_en')->nullable();
            $table->string('video_url')->nullable();
            $table->date('completed_at')->nullable();
            $table->json('tags')->nullable();
            $this->publishing($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('case_studies', function (Blueprint $table) {
            $table->id();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->string('client_name')->nullable();
            $table->boolean('client_approved')->default(false);
            $table->string('industry_ar')->nullable();
            $table->string('industry_en')->nullable();
            $table->longText('overview_ar')->nullable();
            $table->longText('overview_en')->nullable();
            $table->longText('challenge_ar')->nullable();
            $table->longText('challenge_en')->nullable();
            $table->longText('approach_ar')->nullable();
            $table->longText('approach_en')->nullable();
            $table->longText('execution_ar')->nullable();
            $table->longText('execution_en')->nullable();
            $table->longText('campaign_ar')->nullable();
            $table->longText('campaign_en')->nullable();
            // [{label_ar, label_en, value, source_note}] — only ever entered from verified client data.
            $table->json('results')->nullable();
            $table->boolean('results_verified')->default(false);
            $this->publishing($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 32);            // portfolio_item | case_study
            $table->unsignedBigInteger('linkable_id');
            $table->unique(['service_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id']);
        });

        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_category_id')->nullable()->constrained('article_categories')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('excerpt_ar')->nullable();
            $table->text('excerpt_en')->nullable();
            $table->longText('body_ar')->nullable();
            $table->longText('body_en')->nullable();
            $table->unsignedSmallInteger('reading_minutes')->nullable();
            $table->string('video_url')->nullable();
            $this->publishing($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('slug_ar')->unique();
            $table->string('slug_en')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->longText('content_ar')->nullable();
            $table->longText('content_en')->nullable();
            $table->string('template', 24)->default('default');
            $table->boolean('is_system')->default(false);          // about / contact / legal pages keep their slug
            $this->publishing($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // One working draft plus an immutable history of published versions.
        Schema::create('homepage_versions', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->index();                 // draft | published
            $table->json('sections');                              // ordered list of section configs
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('author_name');
            $table->string('author_role_ar')->nullable();
            $table->string('author_role_en')->nullable();
            $table->string('company')->nullable();
            $table->text('quote_ar')->nullable();
            $table->text('quote_en')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('consent_given')->default(false);      // written permission to publish
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->json('social')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('location', 24)->unique();              // header | footer
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('label_ar');
            $table->string('label_en');
            $table->string('url', 500);
            $table->boolean('open_in_new_tab')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('subject')->nullable();
            $table->text('message');
            $table->json('attribution')->nullable();               // UTM / referrer captured at submit time
            $table->string('status', 16)->default('new')->index();  // new | read | replied | archived
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        foreach (['contact_messages', 'menu_items', 'menus', 'team_members', 'testimonials', 'homepage_versions', 'page_revisions', 'pages', 'articles', 'article_categories', 'service_links', 'case_studies', 'portfolio_items', 'services', 'service_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
