<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'          => fake()->city(),
            'province'      => fake()->state(),
            'price_per_kg'  => fake()->randomElement([10000, 15000, 20000, 25000]),
            'is_active'     => true,
            'notes'         => null,
        ];
    }
}
