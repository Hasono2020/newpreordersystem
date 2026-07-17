<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'                 => fake()->city(),
            'province'             => fake()->state(),
            'price_per_kg'         => fake()->randomElement([10000, 15000, 20000, 25000]),
            'flat_fee'             => null,
            'flat_fee_subsidy_cap' => null,
            'is_active'            => true,
            'notes'                => null,
        ];
    }

    /** Create a flat-fee shipping area. */
    public function flatFee(float $fee, ?float $subsidyCap = null): static
    {
        return $this->state([
            'flat_fee'             => $fee,
            'flat_fee_subsidy_cap' => $subsidyCap,
            'price_per_kg'         => 0,
        ]);
    }
}
