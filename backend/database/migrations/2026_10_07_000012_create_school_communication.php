<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assessments precede linked private conversations.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_threads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('assessment_id')->nullable()->constrained('official_assessments')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 32);
            $t->string('subject', 191);
            $t->string('status', 16)->default('open');
            $t->timestamps();
        });
        Schema::create('school_thread_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('school_threads')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('eligibility', 16);
            $t->unsignedBigInteger('last_read_message_id')->default(0);
            $t->unique(['thread_id', 'user_id']);
            $t->index(['user_id', 'thread_id']);
        });
        Schema::create('school_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('school_threads')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_messages');
        Schema::dropIfExists('school_thread_participants');
        Schema::dropIfExists('school_threads');
    }
};
