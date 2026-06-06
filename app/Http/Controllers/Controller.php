<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function adminOnly(string $action = 'perform this action'): void
    {
        if (!auth()->user()?->isAdmin()) {
            abort(403, "Only admins can {$action}.");
        }
    }
}
