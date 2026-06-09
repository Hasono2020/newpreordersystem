<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            abort(403, "You don't have permission to do this. Required: {$permission}");
        }

        return $next($request);
    }
}
