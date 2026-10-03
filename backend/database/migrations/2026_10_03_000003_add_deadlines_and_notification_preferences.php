<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
            $table->timestamp('last_digest_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropIndex(['due_at']));
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropColumn('due_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['notification_preferences', 'last_digest_at']));
    }
};
