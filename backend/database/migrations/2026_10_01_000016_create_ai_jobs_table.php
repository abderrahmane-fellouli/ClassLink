<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_jobs - §11 / §15.3 « Exécution : en arrière-plan, avec suivi de l'état
 * (queued, processing, done, failed) »
 * Index sur file_hash (RG-14 : un même fichier n'est pas retraité).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();

            // quiz | flashcard  (§15.1)
            $table->string('target', 20)->default('quiz');

            $table->string('file_hash', 64);
            $table->string('original_name')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('page_count')->nullable();

            // queued | processing | done | failed
            $table->string('status', 20)->default('queued');
            $table->string('provider')->nullable();
            $table->text('error')->nullable();

            // Résultats : toujours des brouillons (F-IA-03, RG-11).
            $table->foreignId('quiz_id')->nullable()->constrained('quizzes')->nullOnDelete();
            $table->foreignId('deck_id')->nullable()->constrained('flashcard_decks')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('file_hash');
            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
