<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('level')->default('junior');
        });

        // The team lead's evaluation of a completed sprint: one per sprint.
        Schema::create('sprint_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status');
            // The user's level when the sprint was evaluated: promotion counts per level.
            $table->string('level');
            // Area => 0..100, or null when the area could not be judged.
            $table->json('scores')->nullable();
            $table->unsignedTinyInteger('overall')->nullable();
            $table->boolean('passed')->nullable();
            $table->text('communication_evidence')->nullable();
            $table->json('questions')->nullable();
            $table->json('answers')->nullable();
            $table->json('answer_notes')->nullable();
            $table->text('strengths')->nullable();
            $table->text('growth')->nullable();
            $table->text('advice')->nullable();
            $table->string('promoted_to')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_evaluations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
