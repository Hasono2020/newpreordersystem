<?php
namespace Database\Factories;
use App\Models\ShippingArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'                     => fake()->name(),
            'phone'                    => '08' . fake()->unique()->numerify('#########'),
            'address'                  => fake()->address(),
            'type'                     => fake()->randomElement(['customer', 'reseller']),
            'notes'                    => null,
            'default_shipping_area_id' => ShippingArea::factory(),
            'created_by'               => User::factory(),
        ];
    }
}
