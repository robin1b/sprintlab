<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Place in the track of the level (junior sprint 1, 2, 3 ...). Null for a demo or an extra.
        Schema::table('scenarios', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
