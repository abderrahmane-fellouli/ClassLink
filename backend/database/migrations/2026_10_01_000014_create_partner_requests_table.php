<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * partner_requests - §11 (F-PAR-03)
 * Contrainte : unique (from_user_id, to_user_id, classroom_id).
 * status : pending | accepted | rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['from_user_id', 'to_user_id', 'classroom_id']);
            $table->index(['to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_requests');
    }
};
