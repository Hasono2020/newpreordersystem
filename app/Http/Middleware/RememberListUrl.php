<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Remembers the full URL (page number + filters) of the last visited
 * "list" page for each major section, so detail/edit pages can send the
 * user back to exactly where they were instead of page 1.
 *
 * Only GET requests to the index route of each section are remembered.
 * The remembered URL is read via the list_return_url() helper.
 */
class RememberListUrl
{
    /** Route-name prefix => session key */
    private const SECTIONS = [
        'orders'    => 'list_url.orders',
        'products'  => 'list_url.products',
        'customers' => 'list_url.customers',
        'payments'  => 'list_url.payments',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get') && $request->route()) {
            $routeName = $request->route()->getName();
            foreach (self::SECTIONS as $section => $sessionKey) {
                // Match exactly the index route, e.g. "orders.index"
                if ($routeName === $section . '.index') {
                    $request->session()->put($sessionKey, $request->fullUrl());
                    break;
                }
            }
        }

        return $next($request);
    }

    /**
     * Get the remembered list URL for a section, or a fallback route if none stored.
     * Usage in Blade: {{ \App\Http\Middleware\RememberListUrl::returnUrl('orders') }}
     */
    public static function returnUrl(string $section, ?string $fallbackRoute = null): string
    {
        $sessionKey = self::SECTIONS[$section] ?? null;
        $stored = $sessionKey ? session($sessionKey) : null;
        if ($stored) return $stored;
        return route($fallbackRoute ?? ($section . '.index'));
    }
}