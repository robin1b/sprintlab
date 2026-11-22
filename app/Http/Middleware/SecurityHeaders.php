<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic security headers on every response, error pages included.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Must happen before the view is rendered: @vite prints this nonce.
        Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Sprintlab is never shown inside another site.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $response->headers->set(
            config('sprintlab.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
            $this->policy(),
        );

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /**
     * Scripts only from this site or with the nonce of this response. Styles
     * may be inline (Vue and the sidebar set style attributes). Avatars come
     * from GitHub.
     */
    private function policy(): string
    {
        $nonce = Vite::cspNonce();
        $script = "'self' 'nonce-{$nonce}'";
        $style = "'self' 'unsafe-inline'";
        $connect = "'self'";

        // The Vite dev server (npm run dev) serves scripts, styles and a websocket.
        if (Vite::isRunningHot()) {
            $origin = trim((string) file_get_contents(public_path('hot')));
            $socket = preg_replace('~^http~', 'ws', $origin);

            $script .= " {$origin}";
            $style .= " {$origin}";
            $connect .= " {$origin} {$socket}";
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src 'self' data: https://avatars.githubusercontent.com",
            "font-src 'self' data:",
            "connect-src {$connect}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
