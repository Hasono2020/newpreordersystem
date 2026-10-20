<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Remembers the full URL (page number + filters) of the last visited
 * "list" page for each major section, so detail/edit pages can send the
 * user back to exactly where they were instead of page 1.
 *
 * Only GET requests to the index route of each section are remembered.
 * The remembered URL is read via the list_return_url() helper.
 *
 * The session is wiped on logout, so the filters are also saved on the user
 * (users.list_urls, without the page number) and put back into the session at
 * login — that is how "Korea October" is still selected the next day.
 */
class RememberListUrl
{
    /** Route-name prefix => session key */
    private const SECTIONS = [
        'orders'     => 'list_url.orders',
        'products'   => 'list_url.products',
        'customers'  => 'list_url.customers',
        'payments'   => 'list_url.payments',
        'purchasing' => 'list_url.purchasing',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get') && $request->route()) {
            $routeName = $request->route()->getName();
            foreach (self::SECTIONS as $section => $sessionKey) {
                // Match exactly the index route, e.g. "orders.index"
                if ($routeName === $section . '.index') {
                    $request->session()->put($sessionKey, $request->fullUrl());
                    static::saveForUser($request->user(), $section, $request);
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

    /** Save the filters (not the page number) of a list on the user, only when they changed. */
    private static function saveForUser(?User $user, string $section, Request $request): void
    {
        if (!$user) return;

        $url = $request->fullUrlWithoutQuery('page');
        $relative = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) $relative .= '?' . $query;

        $saved = $user->list_urls ?? [];
        if (($saved[$section] ?? null) === $relative) return;

        $saved[$section] = $relative;
        // Straight to the database: no model events, and updated_at is left alone.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)
            ->update(['list_urls' => json_encode($saved)]);
        $user->list_urls = $saved;
        $user->syncOriginal();
    }

    /** At login: put the person's saved list filters back into the fresh session. */
    public static function restoreForUser(?User $user, Request $request): void
    {
        foreach (($user?->list_urls ?? []) as $section => $relative) {
            $sessionKey = self::SECTIONS[$section] ?? null;
            // Only our own relative paths — never an address typed in from elsewhere.
            if (!$sessionKey || !is_string($relative) || !preg_match('#^/(?!/)#', $relative)) continue;
            $request->session()->put($sessionKey, url($relative));
        }
    }
}