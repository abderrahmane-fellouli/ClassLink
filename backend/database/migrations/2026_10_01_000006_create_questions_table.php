<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * questions - §11
 * Suppression en cascade avec le quiz.
 * `type` : single | multiple | true_false  (F-QUI-01)
 * `explanation` : F-QUI-06, revue de la correction après soumission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->text('statement');
            $table->string('type', 20)->default('single');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('quiz_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
