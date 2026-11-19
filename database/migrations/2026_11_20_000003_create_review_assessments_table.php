<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Secret scenario content: the problems hidden in the colleague's pull
        // request. It stays on the scenario ticket and is never copied to `tickets`.
        Schema::table('scenario_tickets', function (Blueprint $table) {
            $table->json('planted_issues')->nullable();
        });

        // How the user's review of a colleague's pull request was judged.
        Schema::create('review_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            // One assessment per submitted review: a repeated webhook is harmless.
            $table->unsignedBigInteger('github_review_id')->unique();
            $table->string('reviewer_login');
            $table->string('status');
            $table->string('verdict')->nullable();
            $table->json('issues')->nullable();
            $table->json('false_positives')->nullable();
            $table->unsignedTinyInteger('clarity')->nullable();
            $table->unsignedTinyInteger('tone')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_assessments');

        Schema::table('scenario_tickets', function (Blueprint $table) {
            $table->dropColumn('planted_issues');
        });
    }
};
