<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * materials - §11 (F-CON-01, F-CON-02, F-CON-03)
 * Index sur classroom_id. Les fichiers sont stockes sur un disque externe
 * prive (§16) et servis uniquement via une URL temporaire signee apres
 * verification de l'autorisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->string('title');

            // Organisation par chapitre (F-CON-01).
            $table->string('chapter')->nullable();

            // file | link
            $table->string('type', 20)->default('file');
            $table->string('path_or_url')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('classroom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
