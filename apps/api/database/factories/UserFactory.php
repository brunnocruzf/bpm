<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => 'u_'.Str::lower(Str::random(8)),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'area' => 'Área de teste',
            'roles' => ['Bpm.Initiator', 'Bpm.TaskWorker'],
            'groups' => [],
            'is_active' => true,
        ];
    }

    /** @param list<string> $roles */
    public function roles(array $roles): static
    {
        return $this->state(fn () => ['roles' => $roles]);
    }

    /** @param list<string> $groups */
    public function groups(array $groups): static
    {
        return $this->state(fn () => ['groups' => $groups]);
    }
}
