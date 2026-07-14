<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'           => fake()->company(),
            'contact_person' => fake()->name(),
            'phone'          => '08' . fake()->numerify('##########'),
            'country'        => fake()->randomElement(['China', 'Indonesia']),
            'notes'          => null,
            'is_active'      => true,
        ];
    }
}
