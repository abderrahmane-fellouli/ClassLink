<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_providers - §11 / §15.2
 * Contrainte : name unique.
 *
 * Les clés secrètes ne sont PAS stockées ici : §15.2 précise « Les clés
 * secrètes restent dans les variables d'environnement. » L'administrateur
 * pilote uniquement l'ordre, l'activation et le quota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // Ordre de tentative croissant.
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('daily_limit')->default(10);
            $table->unsignedInteger('used_today')->default(0);
            $table->timestamp('last_reset_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
