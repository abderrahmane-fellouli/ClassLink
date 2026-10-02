<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Par défaut, la base est peuplée avec les données de démonstration.
 * En production, lancer `php artisan db:seed --class=DatabaseSeeder`
 * ne doit rien faire de silencieux : voir DatabaseSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Environnement production : données de démonstration NON chargées.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
