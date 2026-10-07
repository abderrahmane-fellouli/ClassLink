<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Institutional dependency order: groups/offerings precede assessments.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offering_id')->constrained('module_offerings')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title');
            $t->string('type', 32);
            $t->date('assessed_on');
            $t->decimal('maximum_score', 9, 2)->default(20);
            $t->decimal('coefficient', 9, 2)->default(1);
            $t->string('state', 16)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('roster_version');
            $t->string('eligibility_signature', 64)->nullable();
            $t->unsignedBigInteger('draft_revision_id')->nullable();
            $t->unsignedBigInteger('published_revision_id')->nullable();
            $t->timestamps();
            $t->index(['offering_id', 'state']);
        });
        Schema::create('assessment_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained('official_assessments')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->string('display_name');
            $t->unique(['assessment_id', 'student_id']);
        });
        Schema::create('grade_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained('official_assessments')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('reason', 1000)->nullable();
            $t->string('publication_summary', 1000)->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['assessment_id', 'sequence']);
        });
        Schema::create('grade_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('revision_id')->constrained('grade_revisions')->restrictOnDelete();
            $t->foreignId('assessment_id')->constrained('official_assessments')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->decimal('score', 9, 2)->nullable();
            $t->string('status', 16)->default('ungraded');
            $t->text('feedback')->nullable();
            $t->timestamps();
            $t->unique(['revision_id', 'student_id']);
        });
        Schema::create('grade_import_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('assessment_id')->constrained('official_assessments')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('roster_version');
            $t->json('rows');
            $t->json('errors');
            $t->timestamp('expires_at');
            $t->timestamp('committed_at')->nullable();
            $t->unsignedInteger('committed_version')->nullable();
            $t->timestamps();
        });
        Schema::create('school_notification_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 128);
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('type', 64);
            $t->json('payload');
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->unique(['event_key', 'user_id']);
        });
        Schema::table('notifications', function (Blueprint $t) {
            $t->unsignedBigInteger('school_outbox_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropUnique(['school_outbox_id']);
            $t->dropColumn('school_outbox_id');
        });
        foreach (['school_notification_outbox', 'grade_import_batches', 'grade_entries', 'grade_revisions', 'assessment_candidates', 'official_assessments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
