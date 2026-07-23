<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Trip;
use App\Models\Customer;
use App\Models\ShippingArea;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Databases the test suite is allowed to touch. Anything else aborts.
     */
    protected const ALLOWED_TEST_DATABASES = [
        'newpreordersystem_test',
        ':memory:',
    ];

    /**
     * HARD SAFETY GUARD - do not remove.
     *
     * RefreshDatabase runs `migrate:fresh`, which DROPS EVERY TABLE in
     * whatever database the app happens to be connected to. phpunit.xml
     * overrides DB_DATABASE to a dedicated test database, but that override
     * silently does nothing if Laravel is reading a CACHED config
     * (bootstrap/cache/config.php) instead of environment variables - in
     * which case the target is the REAL database.
     *
     * This guard runs before RefreshDatabase does anything, so a
     * misconfigured environment fails loudly instead of destroying data.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database   = config("database.connections.{$connection}.database");

        if (in_array($database, self::ALLOWED_TEST_DATABASES, true)) {
            return;
        }

        throw new \RuntimeException(
            "\n\n"
            . "==================== TEST RUN ABORTED ====================\n"
            . "Tests are pointed at database [{$database}] on connection [{$connection}].\n"
            . "That is NOT an approved test database, and RefreshDatabase would\n"
            . "DROP EVERY TABLE in it.\n\n"
            . "Most likely cause: a cached config file is overriding phpunit.xml.\n"
            . "Fix with:\n"
            . "    php artisan config:clear\n"
            . "    php artisan cache:clear\n\n"
            . "Then make sure the test database exists:\n"
            . "    CREATE DATABASE newpreordersystem_test;\n"
            . "==========================================================\n"
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Disable CSRF for test requests.
        //
        // Laravel normally skips CSRF automatically when app.env === 'testing'.
        // Disabling the middleware explicitly makes tests independent of that,
        // so a stale config cache can't silently turn every POST/PUT/DELETE
        // into a 419.
        //
        // NOTE: setting an X-XSRF-TOKEN header does NOT work - Laravel expects
        // that header to be an ENCRYPTED value and throws DecryptException on a
        // plain string, which is exactly what produces the 419s.
        foreach ([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ] as $middleware) {
            if (class_exists($middleware)) {
                $this->withoutMiddleware($middleware);
            }
        }
    }

    public function adminUser(): User    { return User::factory()->admin()->create(); }
    public function staffUser(array $e = []): User { return User::factory()->staff()->create($e); }
    public function financeUser(): User  { return User::factory()->finance()->create(); }
    public function ownDataStaff(): User { return User::factory()->ownDataOnly()->create(); }

    public function openTrip(): Trip
    {
        $admin = User::factory()->admin()->create();
        return Trip::factory()->open()->create(['created_by' => $admin->id]);
    }

    public function shippingArea(): ShippingArea { return ShippingArea::factory()->create(); }

    public function customer(?User $createdBy = null): Customer
    {
        $area = $this->shippingArea();
        return Customer::factory()->create([
            'default_shipping_area_id' => $area->id,
            'created_by'               => ($createdBy ?? $this->adminUser())->id,
        ]);
    }
}