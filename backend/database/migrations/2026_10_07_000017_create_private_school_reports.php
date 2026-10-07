<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reports reference existing private threads without granting history access.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('school_threads')->restrictOnDelete();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->string('status', 16)->default('open');
            $t->text('reason');
            $t->text('response')->nullable();
            $t->timestamps();
            $t->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_reports');
    }
};
