<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Existing record IDs and published legacy assignments are preserved.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['materials', 'announcements', 'assignments', 'quizzes', 'flashcard_decks'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('offering_id')->nullable()->constrained('module_offerings')->restrictOnDelete();
            });
        }
        Schema::table('assignments', function (Blueprint $t) {
            // Existing assignments retain their previous published behavior.
            $t->string('publication_status', 16)->default('published')->index();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $t) {
            $t->dropIndex(['publication_status']);
            $t->dropColumn('publication_status');
        });
        foreach (['materials', 'announcements', 'assignments', 'quizzes', 'flashcard_decks'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('offering_id'));
        }
    }
};
