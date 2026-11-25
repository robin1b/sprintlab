<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    /**
     * The day the privacy text was last changed. Update it with the text.
     */
    public const UPDATED_ON = '2026-11-25';

    /**
     * Show the privacy statement. The page is public: a visitor can read it
     * before signing in.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Privacy', [
            'updated_on' => self::UPDATED_ON,
            'contact_email' => config('sprintlab.contact_email'),
            'contact_url' => config('sprintlab.contact_url'),
            // The cookies live as long as the session, so the text follows the setting.
            'session_minutes' => (int) config('session.lifetime'),
            'webhook_retention_days' => (int) config('sprintlab.retention.webhook_days'),
            'log_retention_days' => (int) config('logging.channels.daily.max_files'),
        ]);
    }
}
