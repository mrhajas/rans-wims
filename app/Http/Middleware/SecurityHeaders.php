<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Browser-side protections on every response: no framing by other sites (clickjacking), no content-type guessing, a short referrer, camera and microphone only
 * for WIMS itself (face login, scanners, document camera), HTTPS pinned once the site is on HTTPS, and no "X-Powered-By" advertising the PHP version.
 * The content security policy here only holds the directives that cannot break a page (framing, base address, form targets, plugins); scripts and styles
 * are not restricted because the pages carry inline scripts of the Inertia and charts libraries.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! config('security.headers', true) || ! method_exists($response, 'headers') && ! isset($response->headers)) {
            return $response;
        }
        $h = $response->headers;
        $set = function (string $name, string $value) use ($h) {
            if (! $h->has($name)) {
                $h->set($name, $value);
            }
        };
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), interest-cohort=()');
        $set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
        $set('Cross-Origin-Resource-Policy', 'same-origin');
        if ($request->isSecure()) {
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $h->remove('X-Powered-By');
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        return $response;
    }
}
