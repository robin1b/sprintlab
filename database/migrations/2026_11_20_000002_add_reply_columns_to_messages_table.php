<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // On a user message: set once it got a reply or was deliberately left without one.
            $table->timestamp('answered_at')->nullable()->after('read_at');

            // On an AI reply: which model wrote it and what it cost. Scripted messages leave these empty.
            $table->string('model')->nullable()->after('answered_at');
            $table->unsignedInteger('input_tokens')->default(0)->after('model');
            $table->unsignedInteger('output_tokens')->default(0)->after('input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['answered_at', 'model', 'input_tokens', 'output_tokens']);
        });
    }
};
