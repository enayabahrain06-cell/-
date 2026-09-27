<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+9733'.fake()->unique()->numerify('#######'),
            'email' => null,
            'password' => static::$password ??= Hash::make('password'),
            'gender' => 'male', // deterministic: gender rules depend on it; use ->state(['gender' => 'female']) for the girls track
            'locale' => 'ar',
            'is_active' => true,
            'phone_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withoutPassword(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    public function role(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role));
    }
}
