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
     * Bypass CSRF for all test HTTP requests.
     * Tests use actingAs() for authentication — CSRF adds no security value
     * in the test environment and causes 419 responses.
     */
    protected bool $withCsrfBypass = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->withCsrfBypass) {
            // Works in all Laravel versions — sets the session _token to match
            // what the test client will send, bypassing CSRF validation cleanly.
            $this->session(['_token' => 'test-token']);
            $this->withHeader('X-XSRF-TOKEN', 'test-token');
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
