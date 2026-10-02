<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notifications - §11 (F-NOT-01)
 * Index sur (user_id, read_at).
 *
 * Table applicative conforme au dictionnaire §11 (user_id, type, payload
 * json, read_at) — volontairement distincte de la table `notifications` par
 * défaut de Laravel, dont la forme ne correspond pas au modèle de données
 * demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->json('payload');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
