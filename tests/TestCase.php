<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\Models\User;
use App\Models\Trip;
use App\Models\Customer;
use App\Models\ShippingArea;

abstract class TestCase extends BaseTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────

    protected function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    protected function staffUser(array $extra = []): User
    {
        return User::factory()->staff()->create($extra);
    }

    protected function financeUser(): User
    {
        return User::factory()->finance()->create();
    }

    protected function ownDataStaff(): User
    {
        return User::factory()->ownDataOnly()->create();
    }

    protected function openTrip(): Trip
    {
        $admin = User::factory()->admin()->create();
        return Trip::factory()->open()->create(['created_by' => $admin->id]);
    }

    protected function shippingArea(): ShippingArea
    {
        return ShippingArea::factory()->create();
    }

    protected function customer(?User $createdBy = null): Customer
    {
        $area = $this->shippingArea();
        return Customer::factory()->create([
            'default_shipping_area_id' => $area->id,
            'created_by'               => ($createdBy ?? $this->adminUser())->id,
        ]);
    }
}
