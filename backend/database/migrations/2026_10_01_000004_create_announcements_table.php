<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * announcements - §11 (F-CON-04)
 * Index sur classroom_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');

            // F-CON-04 : épinglage d'une annonce.
            $table->boolean('pinned')->default(false);

            $table->timestamps();

            $table->index('classroom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
