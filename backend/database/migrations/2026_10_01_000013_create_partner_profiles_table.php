<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * partner_profiles - §11 (F-PAR-01, RG-17)
 * `opt_in` : le profil partenaire est désactivé par défaut.
 * RG-17 + F-PAR-04 : ce profil n'affiche jamais l'adresse email ; l'API ne
 * renvoie que le nom d'affichage, les compétences et les disponibilités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // RG-17 : désactivé par défaut.
            $table->boolean('opt_in')->default(false);
            $table->json('skills')->nullable();
            $table->json('availability')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_profiles');
    }
};
