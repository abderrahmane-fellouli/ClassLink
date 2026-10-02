<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * quizzes - §11 (F-QUI-01 à F-QUI-07)
 * Index sur (classroom_id, status).
 *
 * `source` : manual | ai      (F-IA-01, F-IA-02)
 * `reviewed` : F-IA-03 — un quiz généré par l'IA ne peut être publié
 *              qu'après relecture par l'enseignant. Le drapeau est à false
 *              à la création d'un brouillon IA et l'endpoint /publish le
 *              refuse tant qu'il n'est pas passé à true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');

            // draft | published   (RG-11 : seuls les quiz publiés sont visibles)
            $table->string('status', 20)->default('draft');

            // manual | ai
            $table->string('source', 20)->default('manual');

            $table->boolean('reviewed')->default(true);
            $table->unsignedInteger('time_limit_min')->nullable();
            $table->unsignedInteger('max_attempts')->default(1);
            $table->boolean('shuffle')->default(false);
            $table->boolean('show_answers')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['classroom_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
