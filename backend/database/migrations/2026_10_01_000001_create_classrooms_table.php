<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * classrooms - §11
 * Contrainte : join_code unique, index sur teacher_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('subject');
            $table->string('group_label');
            $table->string('school_year');

            // RG-08 : un code d'invitation est unique.
            $table->string('join_code', 16)->unique();

            $table->boolean('join_enabled')->default(true);

            // active | archived  (RG-10 : une classe archivée est en lecture seule)
            $table->string('status', 20)->default('active');
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index('teacher_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
