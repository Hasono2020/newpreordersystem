<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // SQLite doesn't support REGEXP natively — register it as a custom function.
        // This runs before migrations, so tests using RefreshDatabase work correctly.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction(
                'REGEXP',
                fn($pattern, $value) => preg_match('/' . $pattern . '/', (string) $value) ? 1 : 0
            );
        }
    }
}
