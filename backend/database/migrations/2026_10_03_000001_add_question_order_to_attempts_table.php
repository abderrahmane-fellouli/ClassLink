<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-QUI-02 / US-26 — ordre des questions servi à l'étudiant.
 *
 * `shuffle` était stocké sur le quiz mais jamais appliqué : l'ordre venait
 * entièrement du client, donc contournable. L'ordre réellement servi est
 * désormais figé sur la tentative.
 *
 * Colonne nullable et additif : les tentatives existantes gardent `NULL`, et
 * l'ordre des questions est alors simplement celui du quiz (`position`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->json('question_order')->nullable()->after('attempt_no');
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn('question_order');
        });
    }
};