<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which lessons of a course a user marked as understood or not.
        Schema::create('course_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('course', 64);
            $table->string('lesson', 64);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['user_id', 'course', 'lesson']);
        });

        // Every time a user finished the quiz of a course.
        Schema::create('course_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('course', 64);
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('total');
            $table->json('answers');
            $table->timestamps();

            $table->index(['user_id', 'course']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_quiz_attempts');
        Schema::dropIfExists('course_progress');
    }
};
