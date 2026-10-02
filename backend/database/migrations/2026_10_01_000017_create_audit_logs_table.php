<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs - §11 / RG-20
 * Index sur (action, created_at).
 * Actions sensibles tracées : connexion, déconnexion, décision d'adhésion,
 * changement de rôle, configuration IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
