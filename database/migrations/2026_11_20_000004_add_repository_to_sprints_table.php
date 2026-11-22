<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sprints', function (Blueprint $table) {
            // The repository where colleagues open their pull requests.
            $table->foreignId('repository_id')->nullable()->after('scenario_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sprints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repository_id');
        });
    }
};
