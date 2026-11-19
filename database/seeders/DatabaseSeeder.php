<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Scenario\ScenarioImporter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'github_id' => 1,
            'github_username' => 'dev-user',
            'name' => 'Dev User',
            'email' => 'dev@example.com',
        ]);

        // The demo scenario that ships with the repository.
        app(ScenarioImporter::class)->importFile(resource_path('scenarios/demo-sprint.json'));
    }
}
