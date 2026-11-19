<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a webhook call through only when GitHub signed it with our secret.
 *
 * GitHub computes an HMAC of the request body with the webhook secret and
 * sends it in the X-Hub-Signature-256 header. We compute the same and
 * compare. Without this check anyone could post fake pull request events.
 */
class VerifyGitHubSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('github.webhook_secret');

        if ($secret === '') {
            abort(503, 'The GitHub webhook secret is not configured.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        $given = (string) $request->header('X-Hub-Signature-256');

        // hash_equals compares in constant time, so the response time does
        // not leak how much of the signature was right.
        if (! hash_equals($expected, $given)) {
            abort(401, 'Invalid signature.');
        }

        return $next($request);
    }
}
