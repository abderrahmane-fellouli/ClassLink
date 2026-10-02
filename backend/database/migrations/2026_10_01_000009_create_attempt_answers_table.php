<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attempt_answers - §11
 * Contrainte : unique (attempt_id, question_id).
 *
 * RG-13 : la correction est faite côté serveur ; ces colonnes ne sont
 * lues par le navigateur qu'après soumission (GET /attempts/{id}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->json('selected_option_ids');
            $table->boolean('is_correct')->default(false);
            $table->decimal('awarded_score', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_answers');
    }
};
