<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Offerings and private threads exist before these scoped extensions.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_access_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('module_offerings')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->string('reason', 1000);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['offering_id', 'student_id']);
        });
        Schema::table('school_threads', function (Blueprint $t) {
            $t->foreignId('offering_id')->nullable()->constrained('module_offerings')->restrictOnDelete();
            $t->string('notice_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('school_threads', function (Blueprint $t) {
            $t->dropConstrainedForeignId('offering_id');
            $t->dropUnique(['notice_key']);
            $t->dropColumn('notice_key');
        });
        Schema::dropIfExists('module_access_grants');
    }
};
