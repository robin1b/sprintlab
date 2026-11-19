<?php

namespace App\Support;

use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an error response into the Sprintlab error page.
 *
 * Registered in bootstrap/app.php. Returning null keeps Laravel's own response.
 */
class ErrorPage
{
    private const STATUSES = [403, 404, 429, 500, 503];

    public static function render(ExceptionResponse $response): ?Response
    {
        $request = $response->request;
        $status = $response->statusCode();

        // GitHub's webhooks, JSON clients and the health check need the real answer.
        if ($request->expectsJson() || $request->is('webhooks/*', 'up')) {
            return null;
        }

        // With debug on, Laravel's own error page says more.
        if ($status >= 500 && config('app.debug')) {
            return null;
        }

        // The form was open for too long: back to the page with a message, not a dead end.
        if ($status === 419) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'The page expired. Please try again.']);

            return back();
        }

        if (! in_array($status, self::STATUSES, true)) {
            return null;
        }

        $page = $response->render('ErrorPage', ['status' => $status]);

        if ($status >= 500) {
            // No shared data: when the database is the problem, loading the
            // user's sprint for the page would fail again.
            Inertia::flushShared();

            // The host replaces every 5xx body with its own bare page, so the
            // browser gets our page with status 200. The page shows the real
            // status and the exception is logged as usual.
            return $page->toResponse($request)->setStatusCode(200);
        }

        // Shared data (the signed-in user) needs a session. An address without
        // a route never went through the web middleware and has none.
        return ($request->hasSession() ? $page->withSharedData() : $page)->toResponse($request);
    }
}
