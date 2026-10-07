<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Uncommitted institutional sequence, after the deployed sessions migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name', 32)->unique();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 16)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::table('classrooms', function (Blueprint $t) {
            $t->unsignedBigInteger('teacher_id')->nullable()->change();
            $t->boolean('is_official')->default(false)->index();
            $t->foreignId('academic_year_id')->nullable()->constrained('academic_years')->restrictOnDelete();
            $t->string('official_code', 64)->nullable();
            $t->string('filiere', 191)->nullable();
            $t->string('level', 64)->nullable();
            $t->unsignedInteger('roster_version')->default(1);
            $t->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('coordinator_can_manage_roster')->default(false);
            $t->boolean('delegate_notices_enabled')->default(false);
            $t->unique(['academic_year_id', 'official_code']);
        });
        Schema::create('school_modules', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->unique();
            $t->string('name');
            $t->string('status', 16)->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('module_offerings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('module_id')->constrained('school_modules')->restrictOnDelete();
            $t->string('status', 16)->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['classroom_id', 'module_id']);
        });
        Schema::create('teaching_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('module_offerings')->restrictOnDelete();
            $t->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            // Nullable active key permits historical rows but only one active assignment.
            $t->unsignedBigInteger('active_teacher_id')->nullable();
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['offering_id', 'active_teacher_id']);
        });
        Schema::create('school_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('active_student_id')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['academic_year_id', 'active_student_id']);
            $t->index(['classroom_id', 'student_id']);
        });
        Schema::create('class_delegates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('active_slot')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('appointed_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['classroom_id', 'active_slot']);
        });
        Schema::create('school_assignment_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('offering_id')->constrained('module_offerings')->restrictOnDelete();
            $t->string('status', 16)->default('pending');
            $t->string('reason', 500);
            $t->timestamps();
            $t->unique(['teacher_id', 'offering_id']);
        });
    }

    public function down(): void
    {
        foreach (['school_assignment_requests', 'class_delegates', 'school_enrollments', 'teaching_assignments', 'module_offerings', 'school_modules'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('classrooms', function (Blueprint $t) {
            $t->dropIndex(['is_official']);
            $t->dropUnique(['academic_year_id', 'official_code']);
            $t->dropConstrainedForeignId('academic_year_id');
            $t->dropConstrainedForeignId('coordinator_id');
            $t->dropColumn(['is_official', 'official_code', 'filiere', 'level', 'roster_version', 'coordinator_can_manage_roster', 'delegate_notices_enabled']);
        });
        Schema::dropIfExists('academic_years');
        // teacher_id stays nullable: a rollback must not invent an owner or lose groups.
    }
};
