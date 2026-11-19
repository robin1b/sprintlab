<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    |
    | GitHub user ids (numbers, comma separated in .env) that may see the
    | admin pages, such as the AI usage overview.
    |
    */

    'admin_github_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('SPRINTLAB_ADMIN_GITHUB_IDS', '')),
    ))),

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

        // Chat replies from colleagues use a cheaper, faster model.
        'chat_model' => env('SPRINTLAB_CHAT_MODEL', 'claude-haiku-5-5'),

        'chat_replies_per_sprint_per_day' => (int) env('SPRINTLAB_CHAT_REPLIES_PER_DAY', 40),

        // How many earlier messages of a conversation the model gets to see.
        'chat_history_messages' => 20,

        'chat_max_characters' => 2000,

        // A review of a colleague's pull request passes from this score (0-100).
        'review_pass_score' => 50,

        // When this month's AI cost reaches the budget, the colleagues stop
        // calling the model until the next month. 0 switches the cap off.
        'monthly_budget_usd' => (float) env('SPRINTLAB_AI_MONTHLY_BUDGET', 10),

        // US dollars per million tokens, per model family (matched on the
        // start of the model name). Prices change: check them now and then.
        'prices_checked_on' => '2026-11-15',
        'prices' => [
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        ],
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
