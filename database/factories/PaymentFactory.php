<?php
namespace Database\Factories;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id'            => Order::factory(),
            'amount'              => fake()->randomElement([100000, 200000, 300000, 500000]),
            'type'                => 'partial',
            'method'              => fake()->randomElement(['transfer', 'cash']),
            'reference'           => 'TF#' . fake()->numerify('####'),
            'paid_at'             => now(),
            'notes'               => null,
            'recorded_by'         => User::factory(),
            'verification_status' => 'unverified',
            'verified_by'         => null,
            'verified_at'         => null,
            'dispute_note'        => null,
        ];
    }

    public function verified(): static {
        return $this->state(fn() => [
            'verification_status' => 'verified',
            'verified_by'         => User::factory(),
            'verified_at'         => now(),
        ]);
    }

    public function disputed(): static {
        return $this->state(fn() => [
            'verification_status' => 'disputed',
            'verified_by'         => User::factory(),
            'verified_at'         => now(),
            'dispute_note'        => 'Amount does not match bank statement.',
        ]);
    }
}
