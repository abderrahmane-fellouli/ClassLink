<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Dormant additive foundation: no seed, legacy-table alteration, or backfill. */
return new class extends Migration
{
    private const TABLES = ['workspaces', 'workspace_memberships', 'workspace_managers', 'workspace_configurations', 'workspace_capabilities', 'workspace_onboardings'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Workspace integrity currently supports PostgreSQL and SQLite only.');
        }

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('public_key', 64)->unique();
            $t->string('name', 191);
            $t->string('kind', 24)->default('personal');
            $t->string('timezone', 64)->default('UTC');
            $t->string('status', 24)->default('provisioning')->index();
            $t->string('ownership_state', 32)->default('owned');
            $t->unsignedBigInteger('owner_membership_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('workspace_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 16)->default('active');
            $t->timestamp('joined_at');
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['workspace_id', 'user_id']);
            $t->unique(['id', 'workspace_id'], 'workspace_membership_identity');
            $t->index(['user_id', 'status']);
        });
        // A membership in another workspace can never become this workspace's owner.
        Schema::table('workspaces', function (Blueprint $t) {
            $t->foreign(['owner_membership_id', 'id'], 'workspace_owner_membership_fk')
                ->references(['id', 'workspace_id'])->on('workspace_memberships')->restrictOnDelete();
        });
        Schema::create('workspace_managers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('membership_id')->constrained('workspace_memberships')->restrictOnDelete();
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('granted_at');
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['membership_id', 'granted_at']);
        });
        DB::statement('CREATE UNIQUE INDEX workspace_manager_active_unique ON workspace_managers (membership_id) WHERE revoked_at IS NULL');
        Schema::create('workspace_configurations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->unique()->constrained('workspaces')->restrictOnDelete();
            $t->string('preset_id', 32);
            $t->unsignedInteger('preset_version')->default(1);
            $t->string('terminology_preset_id', 32);
            $t->unsignedInteger('terminology_version')->default(1);
            $t->json('terminology_overrides')->nullable();
            $t->json('preferences')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('workspace_capabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $t->string('capability', 64);
            $t->boolean('enabled')->default(false);
            $t->timestamps();
            $t->unique(['workspace_id', 'capability']);
        });
        Schema::create('workspace_onboardings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->unique()->constrained('workspaces')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('draft');
            $t->string('current_step', 64)->nullable();
            $t->json('draft_answers')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('completion_origin', 16)->nullable();
            $t->timestamps();
        });

        foreach ($this->checks() as $table => $checks) {
            foreach ($checks as $name => $expression) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($expression)");
                } else {
                    foreach (Schema::getColumnListing($table) as $column) {
                        $expression = preg_replace('/\b'.preg_quote($column, '/').'\b/', 'NEW.'.$column, $expression);
                    }
                    foreach (['INSERT', 'UPDATE'] as $operation) {
                        $trigger = $name.'_'.strtolower($operation);
                        DB::statement("CREATE TRIGGER $trigger BEFORE $operation ON $table WHEN NOT ($expression) BEGIN SELECT RAISE(ABORT, '$name invariant'); END");
                    }
                }
            }
        }
    }

    private function checks(): array
    {
        return [
            'workspaces' => [
                'workspace_status' => "status IN ('provisioning','active','archived')",
                'workspace_kind' => "kind IN ('personal','organization','institutional')",
                'workspace_version' => 'version >= 1',
                'workspace_ownership' => "ownership_state IN ('owned','unassigned_compatibility','recovery_required') AND (ownership_state <> 'owned' OR owner_membership_id IS NOT NULL OR status = 'provisioning') AND (ownership_state <> 'unassigned_compatibility' OR (owner_membership_id IS NULL AND public_key = 'ofppt-compatibility' AND kind = 'institutional'))",
            ],
            'workspace_memberships' => [
                'workspace_membership_status' => "status IN ('active','suspended','left')",
                'workspace_membership_period' => "(status = 'left' AND ended_at IS NOT NULL AND ended_at >= joined_at) OR (status <> 'left' AND ended_at IS NULL)",
                'workspace_membership_version' => 'version >= 1',
            ],
            'workspace_managers' => [
                'workspace_manager_period' => 'revoked_at IS NULL OR revoked_at >= granted_at',
                'workspace_manager_revoker' => '(revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL)',
            ],
            'workspace_configurations' => [
                'workspace_configuration_versions' => 'revision >= 1 AND preset_version >= 1 AND terminology_version >= 1',
            ],
            'workspace_capabilities' => [
                'workspace_capability_boolean' => 'enabled IN (FALSE, TRUE)',
            ],
            'workspace_onboardings' => [
                'workspace_onboarding_version' => 'version >= 1',
                'workspace_onboarding_status' => "status IN ('draft','completed')",
                'workspace_onboarding_completion' => "(status = 'draft' AND completed_at IS NULL AND completion_origin IS NULL) OR (status = 'completed' AND completed_at IS NOT NULL AND completion_origin IS NOT NULL AND completion_origin IN ('user','migration'))",
            ],
        ];
    }

    public function down(): void
    {
        // A code rollback is not permission to discard new workspace/user data.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Workspace foundation rollback requires empty tables; retain populated schema and use a forward repair.');
            }
        }
        Schema::table('workspaces', function (Blueprint $t) {
            $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['owner_membership_id', 'id'] : 'workspace_owner_membership_fk');
        });
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
