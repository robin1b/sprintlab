<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scenario content: the script of a sprint. Imported from JSON files.
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('level');
            $table->text('summary')->nullable();
            $table->unsignedTinyInteger('duration_days');
            $table->timestamps();
        });

        Schema::create('scenario_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->unsignedTinyInteger('day');
            $table->string('type');
            $table->string('reporter');
            $table->string('title');
            $table->text('description');
            $table->json('acceptance_criteria');
            // false: the ticket only appears when an event releases it.
            $table->boolean('released_at_start')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['scenario_id', 'key']);
        });

        Schema::create('scenario_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('trigger');
            $table->string('ticket_key')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->string('action');
            $table->string('colleague')->nullable();
            $table->json('payload');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['scenario_id', 'key']);
        });

        // A user playing a scenario.
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scenario_id')->constrained();
            $table->string('status');
            $table->unsignedTinyInteger('current_day')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('deadline_at');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // The user's own copy of the scenario tickets, so they can change status.
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scenario_ticket_id')->constrained();
            $table->string('key');
            $table->unsignedTinyInteger('day');
            $table->string('type');
            $table->string('reporter');
            $table->string('title');
            $table->text('description');
            $table->json('acceptance_criteria');
            $table->string('status');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('pull_request_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['sprint_id', 'key']);
        });

        // Chat messages. `colleague` is always the conversation partner.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained()->cascadeOnDelete();
            $table->string('colleague');
            $table->boolean('from_user')->default(false);
            $table->text('body');
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['sprint_id', 'colleague', 'id']);
        });

        // The schedule of scenario events for one sprint. The unique key also
        // guarantees that an event fires only once per sprint.
        Schema::create('sprint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scenario_event_id')->constrained()->cascadeOnDelete();
            $table->timestamp('due_at');
            $table->timestamp('fired_at')->nullable();
            $table->timestamps();

            $table->unique(['sprint_id', 'scenario_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('sprints');
        Schema::dropIfExists('scenario_events');
        Schema::dropIfExists('scenario_tickets');
        Schema::dropIfExists('scenarios');
    }
};
