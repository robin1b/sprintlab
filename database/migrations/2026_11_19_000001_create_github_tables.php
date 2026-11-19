<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user installs the Sprintlab GitHub App on their repository.
        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_login');
            $table->unsignedBigInteger('account_id');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_id')->unique();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->boolean('private')->default(false);
            $table->timestamps();
        });

        Schema::create('pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('author_login');
            $table->string('head_ref');
            $table->string('head_sha', 40);
            $table->string('base_ref');
            $table->string('state');
            $table->boolean('draft')->default(false);
            $table->timestamps();

            $table->unique(['repository_id', 'number']);
        });

        // One review per colleague per commit, so a retried job never posts twice.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->string('colleague');
            $table->string('head_sha', 40);
            $table->string('status');
            $table->string('verdict')->nullable();
            $table->text('summary')->nullable();
            $table->json('comments')->nullable();
            $table->unsignedBigInteger('github_review_id')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['pull_request_id', 'head_sha', 'colleague']);
        });

        // Every webhook call GitHub sends, stored first and processed by a job.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event');
            $table->string('action')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('pull_requests');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('github_installations');
    }
};
