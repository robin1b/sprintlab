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
    | Contact and privacy
    |--------------------------------------------------------------------------
    |
    | Shown on the privacy page. Without an email address the page points to
    | the contact page instead. Webhook messages from GitHub are removed after
    | the number of days below; the privacy page reads the same number.
    |
    */

    'contact_email' => env('SPRINTLAB_CONTACT_EMAIL'),

    'contact_url' => env('SPRINTLAB_CONTACT_URL', 'https://robinbroos.com/contact'),

    'retention' => [
        'webhook_days' => (int) env('SPRINTLAB_WEBHOOK_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | With SPRINTLAB_CSP_REPORT_ONLY=true the browser only reports what the
    | policy would block, it blocks nothing. An emergency switch for when a
    | page turns blank because of the policy.
    |
    */

    'csp_report_only' => (bool) env('SPRINTLAB_CSP_REPORT_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Practice repository
    |--------------------------------------------------------------------------
    |
    | The public GitHub template (owner/name) that learners create their own
    | practice repository from.
    |
    */

    'template_repository' => env('SPRINTLAB_TEMPLATE_REPOSITORY', 'robin1b/workkit'),

    /*
    |--------------------------------------------------------------------------
    | Evaluation after a sprint
    |--------------------------------------------------------------------------
    |
    | Each area gets a score from 0 to 100. The overall score is the weighted
    | average of the areas that could be judged. A user moves up a level after
    | enough passed sprints at their current level.
    |
    */

    'evaluation' => [
        'weights' => [
            'delivery' => 20,
            'code_quality' => 25,
            'review' => 15,
            'communication' => 15,
            'process' => 10,
            'understanding' => 15,
        ],

        'pass_score' => 70,

        'sprints_to_promote' => 3,

        // Longest answer a user can give to one evaluation question.
        'answer_max_characters' => 2000,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Courses
    |--------------------------------------------------------------------------
    |
    | The folder with the course files (one JSON file per course, see
    | docs/courses.md). Tests point it at their own fixtures.
    |
    */

    'courses_path' => env('SPRINTLAB_COURSES_PATH') ?: resource_path('courses'),

];
