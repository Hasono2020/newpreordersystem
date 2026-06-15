<?php

namespace Database\Factories;

use App\Models\CsAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

class CsAgentFactory extends Factory
{
    protected $model = CsAgent::class;

    public function definition(): array
    {
        return [
            'name'      => $this->faker->firstName(),
            'handle'    => null,
            'is_active' => true,
            'notes'     => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}