<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI colleagues
    |--------------------------------------------------------------------------
    |
    | Every review costs money, so reviews can be switched off and are capped
    | per GitHub installation (one installation is one user's repository).
    |
    */

    'ai' => [
        'enabled' => (bool) env('SPRINTLAB_AI_ENABLED', true),

        'review_model' => env('SPRINTLAB_REVIEW_MODEL', 'claude-sonnet-5-5'),

        'reviews_per_installation_per_day' => (int) env('SPRINTLAB_REVIEWS_PER_DAY', 20),

        // A larger diff is cut off before it goes to the model.
        'max_diff_characters' => 60000,

        'max_inline_comments' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scenario files
    |--------------------------------------------------------------------------
    |
    | Folders with scenario JSON files (see `sprintlab:import-scenarios`). The
    | repository is public, so it only ships a demo. Real scenarios, with the
    | answers in them, live in the private second folder on the server.
    |
    */

    'scenario_paths' => [
        resource_path('scenarios'),
        env('SPRINTLAB_SCENARIO_PATH') ?: storage_path('app/private/scenarios'),
    ],

];
