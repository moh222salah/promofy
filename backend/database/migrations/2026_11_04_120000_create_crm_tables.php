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
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('industry')->nullable();
            $table->string('source', 24)->nullable();
            $table->text('notes')->nullable();
            $table->json('attribution')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('industry')->nullable();
            $table->string('source', 24)->default('website')->index();   // instagram|facebook|google|website|whatsapp|referral|direct|other
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('budget', 64)->nullable();
            $table->string('timeline', 64)->nullable();
            $table->string('contact_method', 16)->nullable();
            $table->text('description')->nullable();
            $table->string('priority', 12)->default('normal');           // low|normal|high|urgent
            $table->string('status', 16)->default('new')->index();       // new|contacted|qualified|proposal_sent|negotiation|won|lost
            $table->string('lost_reason')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_followup_at')->nullable();
            $table->timestamp('next_followup_at')->nullable()->index();
            $table->json('attribution')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lead_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 24)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 16);                          // lead | customer
            $table->unsignedBigInteger('subject_id');
            $table->string('type', 16);                                  // note|call|email|whatsapp|meeting|status|assignment|followup|system
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->string('status', 12)->default('open')->index();     // open | done
            $table->string('priority', 12)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('taskable_type', 16)->nullable();             // lead | customer | project
            $table->unsignedBigInteger('taskable_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['taskable_type', 'taskable_id']);
        });
    }

    public function down(): void
    {
        foreach (['tasks', 'crm_activities', 'lead_attachments', 'leads', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
