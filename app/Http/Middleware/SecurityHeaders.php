<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the security headers from PRD §8.6.
 *
 * Generates a per-request CSP nonce, shares it with Vite, Inertia, and the
 * request attribute bag BEFORE the response is rendered, then writes a strict
 * Content-Security-Policy header on the way back out.
 *
 * TLS / HSTS is normally terminated by Laravel Cloud's edge, but
 * setting HSTS at the app layer is defense in depth — if the app ever
 * runs behind a different edge, the policy travels with it.
 */
final class SecurityHeaders
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(24));

        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);
        Inertia::share('cspNonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp($nonce));
        }

        return $response;
    }

    private function csp(string $nonce): string
    {
        $connectSrc = "connect-src 'self'";

        if ($this->app->environment('local')) {
            $connectSrc .= ' ws://vantagejobs.test:* wss://vantagejobs.test:* http://localhost:* https://localhost:* ws://localhost:* wss://localhost:*';
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            $connectSrc,
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
