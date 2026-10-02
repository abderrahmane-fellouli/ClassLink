<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * otp_codes - §11 / §16 « Pour le code email : code haché, 10 minutes,
 * 5 essais, limitation de débit »
 * Contraintes : index sur expires_at, purge régulière.
 *
 * §11 : « Les codes email sont stockés hachés, jamais en clair. »
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            // Nullable : la demande peut précéder la création du compte.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email');

            // Jamais le code en clair.
            $table->string('code_hash', 64);

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('expires_at');
            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
