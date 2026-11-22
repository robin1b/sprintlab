<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_installations', function (Blueprint $table) {
            // "all" or "selected", as GitHub sends it: which repositories the app may see.
            $table->string('repository_selection', 16)->nullable()->after('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('github_installations', function (Blueprint $table) {
            $table->dropColumn('repository_selection');
        });
    }
};
