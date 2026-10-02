<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabriques de test. Les emails respectent toujours les formats de
 * détection de rôle (RG-01, RG-02) pour que les tests restent réalistes.
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        // 13 chiffres -> détecté « student ».
        $studentNumber = (string) $this->faker->unique()->numerify('#############');

        return [
            'email' => $studentNumber.'@ofppt-edu.ma',
            'display_name' => $this->faker->name(),
            'role' => Role::Student->value,
            'role_locked' => false,
            'locale' => 'fr',
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    /** Format enseignant : prenom.nom@ofppt-edu.ma. */
    public function teacher(): static
    {
        $first = Str::of($this->faker->firstName())->lower()->replace(' ', '');
        $last = Str::of($this->faker->lastName())->lower()->replace([' ', "'"], '');

        return $this->state(fn () => [
            'email' => $first.'.'.$last.'@ofppt-edu.ma',
            'display_name' => ucfirst($first).' '.ucfirst($last),
            'role' => Role::Teacher->value,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'email' => 'admin.classlink@ofppt-edu.ma',
            'display_name' => 'Administrateur ClassLink',
            'role' => Role::Admin->value,
            'role_locked' => true,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'email' => 'format.inconnu@ofppt-edu.ma',
            'display_name' => 'Compte en attente',
            'role' => Role::Pending->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
