<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * flashcard_reviews — §11 (F-QUI-08), persistance de la révision.
 *
 * Un deck publié doit pouvoir être repris plus tard : l'étudiant memorise
 * pour chaque carte s'il la connaît (« su ») ou s'il doit la revoir
 * (« à revoir »). L'état était auparavant entièrement côté client
 * (`useState`), donc perdu au rechargement et propre à un seul poste.
 *
 * Le couple (user_id, flashcard_id) est unique : une carte ne porte qu'un
 * seul état de révision par étudiant, mis à jour à chaque nouvelle réponse.
 * L'effacement du deck ou de la carte emporte ces lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flashcard_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('flashcard_id')->constrained('flashcards')->cascadeOnDelete();

            // true = « su », false = « à revoir ».
            $table->boolean('known');

            $table->timestamps();

            $table->unique(['user_id', 'flashcard_id']);
            $table->index('flashcard_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashcard_reviews');
    }
};