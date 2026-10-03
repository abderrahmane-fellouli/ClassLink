<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-IA-03 / RG-11 / US-32 — porte de relecture fermée par défaut.
 *
 * Deux défauts fail-open :
 *  1. `reviewed` avait pour valeur par défaut `true` sur `quizzes` et
 *     `flashcard_decks`. Toute création qui n'écrirait pas explicitement la
 *     colonne naissait donc « relue ».
 *  2. `publish()` réécrivait `reviewed = true` : publier certifiait la
 *     relecture, donc la publication devenait l'acte de relecture.
 *
 * `reviewed_at` rend la relecture vérifiable : il n'est posé que par une
 * confirmation explicite de l'enseignant ou par une modification réelle du
 * contenu, jamais par la création ni par la publication.
 *
 * Migration additive : la colonne est nullable et le changement de valeur par
 * défaut ne touche aucune donnée existante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('reviewed')->default(false)->change();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed');
        });

        Schema::table('flashcard_decks', function (Blueprint $table) {
            $table->boolean('reviewed')->default(false)->change();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
            $table->boolean('reviewed')->default(true)->change();
        });

        Schema::table('flashcard_decks', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
            $table->boolean('reviewed')->default(true)->change();
        });
    }
};