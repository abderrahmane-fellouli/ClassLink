<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users - §11 "Modèle de données"
 *
 * §11 : « Les mots de passe n'existent pas (connexion Microsoft ou code à usage
 * unique). […] La date de naissance n'a aucune colonne. »
 * Il n'y a donc volontairement aucune colonne `password`, ni `birth_date`.
 * Le rôle est toujours calculé côté serveur (§16 « Élévation de rôle »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('display_name');

            // student | teacher | admin | pending | denied
            $table->string('role', 20)->default('pending');
            $table->index('role');

            // RG-03 : un rôle modifié par le super admin est verrouillé et
            // n'est plus recalculé automatiquement par le détecteur.
            $table->boolean('role_locked')->default(false);

            // F-UI-01 : interface bilingue français / anglais uniquement.
            $table->string('locale', 5)->default('fr');

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
