<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Trip;
use App\Models\Customer;
use App\Models\ShippingArea;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register REGEXP function for SQLite so the old data migration works
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', function ($pattern, $value) {
                return preg_match('/' . $pattern . '/', $value) ? 1 : 0;
            });
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    public function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    public function staffUser(array $extra = []): User
    {
        return User::factory()->staff()->create($extra);
    }

    public function financeUser(): User
    {
        return User::factory()->finance()->create();
    }

    public function ownDataStaff(): User
    {
        return User::factory()->ownDataOnly()->create();
    }

    public function openTrip(): Trip
    {
        $admin = User::factory()->admin()->create();
        return Trip::factory()->open()->create(['created_by' => $admin->id]);
    }

    public function shippingArea(): ShippingArea
    {
        return ShippingArea::factory()->create();
    }

    public function customer(?User $createdBy = null): Customer
    {
        $area = $this->shippingArea();
        return Customer::factory()->create([
            'default_shipping_area_id' => $area->id,
            'created_by'               => ($createdBy ?? $this->adminUser())->id,
        ]);
    }
}
