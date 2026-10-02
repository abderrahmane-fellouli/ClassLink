<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * flashcard_decks / flashcards - §11 (F-QUI-08)
 * source : manual | ai — un deck IA reste en brouillon jusqu'à relecture
 * (F-IA-03 appliquée aux flashcards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flashcard_decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->string('title');
            $table->string('source', 20)->default('manual');

            // draft | published
            $table->string('status', 20)->default('draft');
            $table->boolean('reviewed')->default(true);
            $table->timestamps();

            $table->index('classroom_id');
        });

        Schema::create('flashcards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained('flashcard_decks')->cascadeOnDelete();
            $table->text('front');
            $table->text('back');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('deck_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashcards');
        Schema::dropIfExists('flashcard_decks');
    }
};
