<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ClassroomFactory extends Factory
{
    protected $model = Classroom::class;

    public function definition(): array
    {
        return [
            'teacher_id' => User::factory()->teacher(),
            'name' => $this->faker->randomElement([
                'Développement Web', 'Bases de données', 'Réseaux informatiques',
                'Algorithmique', 'Maintenance informatique',
            ]).' '.$this->faker->numberBetween(1, 3),
            'subject' => $this->faker->randomElement([
                'Développement Web', 'Bases de données', 'Réseaux', 'Algorithmique',
            ]),
            'group_label' => $this->faker->randomElement(['TDI 1', 'TDI 2', 'TDI 3']),
            'school_year' => '2025-2026',
            'join_code' => strtoupper(Str::random(8)),
            'join_enabled' => true,
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => 'archived',
            'archived_at' => now(),
            'join_enabled' => false,
        ]);
    }
}
