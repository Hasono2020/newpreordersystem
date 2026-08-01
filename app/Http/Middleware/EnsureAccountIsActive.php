<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AuthController::login() already blocks a deactivated account from logging
 * in. But it doesn't help someone who was ALREADY logged in when an admin
 * flips their account off — most routes aren't wrapped in the perm:
 * middleware, so a stale session could keep browsing (and, until the
 * User::isOwnDataOnly() fix, could even end up seeing MORE than before).
 * This runs on every web request and ends that session immediately.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && !Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Your account has been deactivated.'], 403);
            }

            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been deactivated.']);
        }

        return $next($request);
    }
}
