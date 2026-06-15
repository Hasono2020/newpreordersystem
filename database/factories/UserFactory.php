<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
            'role'              => 'staff',
            'is_active'         => true,
            'permissions'       => null,
            'phone'             => null,
            'notes'             => null,
        ];
    }

    public function admin(): static    { return $this->state(['role' => 'admin']); }
    public function finance(): static  { return $this->state(['role' => 'finance']); }
    public function staff(): static    { return $this->state(['role' => 'staff']); }
    public function inactive(): static { return $this->state(['is_active' => false]); }

    public function ownDataOnly(): static {
        return $this->state(['role' => 'staff', 'permissions' => ['own_data' => true]]);
    }

    public function unverified(): static {
        return $this->state(['email_verified_at' => null]);
    }
}