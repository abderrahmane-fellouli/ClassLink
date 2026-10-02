<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attempts - §11 (RG-12, RG-13)
 * Contrainte : unique (quiz_id, student_id, attempt_no).
 *
 * `expired` : RG-12 — « Une tentative dont le temps est écoulé est soumise
 * automatiquement. » Le serveur corrige alors les réponses déjà enregistrées.
 * `max_score` est figé au moment du démarrage pour que la correction reste
 * stable même si l'enseignant édite le quiz entre-temps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');

            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('expired')->default(false);

            $table->timestamps();

            $table->unique(['quiz_id', 'student_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
