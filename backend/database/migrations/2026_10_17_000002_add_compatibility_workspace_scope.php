<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROOTS = ['academic_years', 'classrooms', 'school_modules', 'assignments', 'quizzes', 'flashcard_decks', 'announcements', 'materials', 'ai_jobs', 'school_threads', 'school_assignment_requests'];

    private const EVENTS = ['audit_event_scopes' => ['audit_logs', 'audit_id'], 'notification_scopes' => ['notifications', 'notification_id'], 'school_outbox_scopes' => ['school_notification_outbox', 'outbox_id']];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Compatibility scope supports PostgreSQL and SQLite.');
        }
        foreach (self::ROOTS as $table) {
            // Native ADD/DROP preserves existing SQLite institutional triggers.
            DB::statement("ALTER TABLE $table ADD COLUMN workspace_id BIGINT NULL REFERENCES workspaces(id) ON DELETE RESTRICT");
            DB::statement("CREATE INDEX {$table}_workspace_scope ON $table (workspace_id)");
        }
        Schema::create('workspace_migration_runs', function (Blueprint $t) {
            $t->id();
            $t->string('operation', 64);
            $t->unsignedInteger('version');
            $t->string('manifest_hash', 64);
            $t->string('target_hash', 64);
            $t->string('inventory_hash', 64);
            $t->enum('mode', ['dry_run', 'apply']);
            $t->enum('state', ['running', 'completed', 'failed']);
            $t->string('stage', 64);
            $t->unsignedInteger('attempts')->default(1);
            $t->json('counts');
            $t->json('checkpoints');
            $t->json('history');
            $t->string('failure_code', 64)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();
            $t->unique(['operation', 'version', 'manifest_hash', 'mode'], 'workspace_operation_identity');
        });
        foreach (self::EVENTS as $table => [$parent, $key]) {
            Schema::create($table, function (Blueprint $t) use ($parent, $key) {
                $t->foreignId($key)->primary()->constrained($parent)->restrictOnDelete();
                $t->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
                $t->enum('classification', ['workspace', 'platform', 'unresolved']);
                $t->timestamp('recorded_at');
                $t->index(['workspace_id', $key]);
            });
            $check = "(classification = 'workspace' AND workspace_id IS NOT NULL) OR (classification <> 'workspace' AND workspace_id IS NULL)";
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_identity CHECK ($check)");
            } else {
                $check = str_replace(['classification', 'workspace_id'], ['NEW.classification', 'NEW.workspace_id'], $check);
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::statement("CREATE TRIGGER {$table}_identity_$operation BEFORE $operation ON $table WHEN NOT ($check) BEGIN SELECT RAISE(ABORT, 'event_scope_identity'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ROOTS as $table) {
            if (Schema::hasColumn($table, 'workspace_id') && DB::table($table)->whereNotNull('workspace_id')->exists()) {
                throw new RuntimeException('Populated compatibility scope cannot be rolled back.');
            }
        }
        foreach (array_merge(['workspace_migration_runs'], array_keys(self::EVENTS)) as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Retain compatibility ledger/event history; use forward repair.');
            }
        }
        DB::transaction(function () {
            foreach (array_reverse(array_keys(self::EVENTS)) as $table) {
                Schema::dropIfExists($table);
            }
            Schema::dropIfExists('workspace_migration_runs');
            foreach (array_reverse(self::ROOTS) as $table) {
                if (Schema::hasColumn($table, 'workspace_id')) {
                    DB::statement("DROP INDEX {$table}_workspace_scope");
                    // Never reconstruct populated SQLite parents: DROP TABLE could
                    // execute legacy ON DELETE CASCADE even with deferred checking.
                    // If unrelated later DDL makes native DROP unsafe, fail atomically.
                    DB::statement("ALTER TABLE $table DROP COLUMN workspace_id");
                }
            }
            if (DB::getDriverName() === 'sqlite') {
                if (DB::select('PRAGMA foreign_key_check')) {
                    throw new RuntimeException('SQLite scope rollback failed integrity validation.');
                }
            }
        });
    }
};
