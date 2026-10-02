<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * options - §11
 * « au moins 2 options par question (règle applicative) » : vérifié par la
 * Form Request de création de quiz, pas par la base.
 *
 * RG-13 : `is_correct` n'est jamais sérialisé par la ressource exposée à
 * l'étudiant avant la soumission de sa tentative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->index('question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
