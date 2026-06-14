<?php
namespace Database\Factories;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'           => 'Trip ' . fake()->unique()->numberBetween(1, 999),
            'destination'    => fake()->city(),
            'trip_date'      => fake()->dateTimeBetween('+7 days', '+30 days'),
            'order_deadline' => fake()->dateTimeBetween('now', '+6 days'),
            'status'         => 'open',
            'notes'          => null,
            'created_by'     => User::factory(),
        ];
    }

    public function open(): static       { return $this->state(['status' => 'open']); }
    public function closed(): static     { return $this->state(['status' => 'order_closed']); }
    public function purchasing(): static { return $this->state(['status' => 'purchasing']); }
}
