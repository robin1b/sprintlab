<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Secret guidance for the AI colleagues. It never goes to the browser.
        Schema::table('scenarios', function (Blueprint $table) {
            $table->text('briefing')->nullable();
            $table->json('colleague_notes')->nullable();
        });

        Schema::table('scenario_tickets', function (Blueprint $table) {
            $table->text('review_notes')->nullable();
            $table->string('reviewer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropColumn(['briefing', 'colleague_notes']);
        });

        Schema::table('scenario_tickets', function (Blueprint $table) {
            $table->dropColumn(['review_notes', 'reviewer']);
        });
    }
};
