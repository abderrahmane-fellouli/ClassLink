<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Material;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMigrationRun;
use App\Services\OfficialGradeService;
use App\Services\OfpptCompatibilityBootstrap;
use App\Services\SchoolCommunicationService;
use App\Services\SchoolSetupService;
use App\Workspace\LegacyWorkspaceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\SchoolFixture;
use Tests\TestCase;

class OfpptCompatibilityTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    private function manifest(User $admin): array
    {
        $identity = ['user_id' => $admin->id, 'identity_sha256' => hash('sha256', mb_strtolower($admin->email))];

        return ['operation' => 'ofppt-compatibility', 'version' => 1, 'environment' => 'testing', 'target_hash' => app(OfpptCompatibilityBootstrap::class)->targetHash(), 'approval_reference' => 'synthetic-reviewed-manifest', 'actor' => $identity, 'managers' => [$identity], 'owner' => null, 'allow_demo_managers' => true];
    }

    private function applyManifest(array $manifest, ?OfpptCompatibilityBootstrap $service = null): array
    {
        config(['workspace_compatibility.apply_enabled' => true]);
        $service ??= app(OfpptCompatibilityBootstrap::class);
        $dry = $service->dryRun($manifest);

        return $service->apply($manifest, $dry['manifest_hash'], $dry['target_hash'], $dry['inventory_hash'], true);
    }

    public function test_dry_run_writes_only_private_control_ledger_not_domain_or_auth_state(): void
    {
        [$admin] = $this->schoolFixture();
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $service = app(OfpptCompatibilityBootstrap::class);
        $manifest = $this->manifest($admin);
        $dry = $service->dryRun($manifest);
        $this->assertSame(0, $dry['conflict_count']);
        $this->assertFalse($dry['domain_writes']);
        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, DB::table('classrooms')->whereNotNull('workspace_id')->count());
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $again = $service->dryRun($manifest);
        $this->assertSame($dry['ledger_id'], $again['ledger_id']);
        $this->assertSame(1, WorkspaceMigrationRun::count());
    }

    public function test_missing_gate_manifest_hash_target_acknowledgement_and_stale_inventory_refuse_before_domain_writes(): void
    {
        $admin = $this->admin();
        $service = app(OfpptCompatibilityBootstrap::class);
        $manifest = $this->manifest($admin);
        $dry = $service->dryRun($manifest);
        foreach ([[false, true, $dry['manifest_hash'], $dry['target_hash'], $dry['inventory_hash']], [true, false, $dry['manifest_hash'], $dry['target_hash'], $dry['inventory_hash']], [true, true, 'wrong', $dry['target_hash'], $dry['inventory_hash']], [true, true, $dry['manifest_hash'], 'wrong', $dry['inventory_hash']], [true, true, $dry['manifest_hash'], $dry['target_hash'], 'stale']] as [$enabled, $paused, $hash, $target, $inventory]) {
            config(['workspace_compatibility.apply_enabled' => $enabled]);
            try {
                $service->apply($manifest, $hash, $target, $inventory, $paused);
                $this->fail('Unsafe apply accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Workspace::count());
                $this->assertSame(0, DB::table('workspace_memberships')->count());
            }
        }
    }

    public function test_manager_manifest_rejects_duplicates_wrong_identity_wrong_role_and_demo_without_local_ack(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $good = $this->manifest($admin);
        $bad = [];
        $bad[] = array_replace($good, ['managers' => []]);
        $bad[] = array_replace($good, ['managers' => [$good['managers'][0], $good['managers'][0]]]);
        $bad[] = array_replace($good, ['managers' => [['user_id' => $admin->id, 'identity_sha256' => str_repeat('0', 64)]]]);
        $bad[] = array_replace($good, ['managers' => [['user_id' => $student->id, 'identity_sha256' => hash('sha256', $student->email)]]]);
        $bad[] = array_replace($good, ['allow_demo_managers' => false]);
        foreach ($bad as $manifest) {
            try {
                app(OfpptCompatibilityBootstrap::class)->dryRun($manifest);
                $this->fail('Invalid manager manifest accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Workspace::count());
            }
        }
    }

    public function test_apply_and_rerun_preserve_existing_rows_ids_roles_sessions_grades_and_private_messages(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $grades = app(OfficialGradeService::class);
        $assessment = $grades->create($teachers[0], $offerings[0], ['title' => 'Synthetic grade', 'type' => 'exam', 'assessed_on' => now()->toDateString(), 'maximum_score' => 20, 'coefficient' => 1]);
        $grades->save($teachers[0], $assessment, 1, array_map(fn ($student) => ['student_id' => $student->id, 'score' => 16, 'status' => 'graded'], $students->all()));
        $grades->publish($teachers[0], $assessment, 2, 'Synthetic publication');
        $grades->correction($teachers[0], $assessment, 3, 'Synthetic correction');
        $thread = app(SchoolCommunicationService::class)->create($students[0], ['kind' => 'private_question', 'classroom_id' => $group->id, 'offering_id' => $offerings[0], 'subject' => 'Synthetic private thread', 'body' => 'Synthetic private body', 'participant_ids' => [$teachers[0]->id]]);
        $this->tokenFor($students[0]);
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $students[0]->id, 'payload' => 'synthetic-private-session', 'last_activity' => time()]);
        $tables = ['users', 'grade_entries', 'grade_revisions', 'official_assessments', 'school_messages', 'school_thread_participants', 'personal_access_tokens', 'sessions', 'audit_logs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $manifest = $this->manifest($admin);
        $result = $this->applyManifest($manifest);
        $this->assertFalse($result['no_op']);
        $workspace = Workspace::sole();
        $this->assertNull($workspace->owner_membership_id);
        $this->assertSame('active', $workspace->status->value);
        $this->assertSame(22, $workspace->capabilities()->where('enabled', true)->count());
        $this->assertSame(4, $workspace->capabilities()->where('enabled', false)->count());
        $this->assertSame('migration', $workspace->onboarding->completion_origin);
        $this->assertSame(DB::table('users')->count(), $workspace->memberships()->count());
        $this->assertSame(1, DB::table('workspace_managers')->count());
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                unset($row->workspace_id);

                return $row;
            })->toJson();
            $original = collect(json_decode($before[$table]))->map(function ($row) {
                unset($row->workspace_id);

                return $row;
            })->toJson();
            $this->assertSame($original, $rows, $table);
        }
        $metadata = DB::table('workspace_memberships')->orderBy('id')->get()->toJson();
        $again = $this->applyManifest($manifest);
        $this->assertTrue($again['no_op']);
        $this->assertSame($metadata, DB::table('workspace_memberships')->orderBy('id')->get()->toJson());
        $this->assertSame(1, Workspace::count());
        $this->assertSame($workspace->id, LegacyWorkspaceScope::resolve('grade_entries', (int) DB::table('grade_entries')->value('id')));
        $this->assertTrue(app(SchoolCommunicationService::class)->canRead($students[0], DB::table('school_threads')->find($thread)));
        $this->assertFalse(app(SchoolCommunicationService::class)->canRead($admin, DB::table('school_threads')->find($thread)));
    }

    public function test_halfway_failure_retains_checkpoints_and_retry_is_safe_without_duplicate_authority(): void
    {
        [$admin] = $this->schoolFixture();
        $manifest = $this->manifest($admin);
        $failing = new class extends OfpptCompatibilityBootstrap
        {
            protected function afterStage(string $stage): void
            {
                if ($stage === 'root:classrooms') {
                    throw new RuntimeException('synthetic_fault');
                }
            }
        };
        try {
            $this->applyManifest($manifest, $failing);
            $this->fail('Injected fault was not reached.');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic_fault', $error->getMessage());
        }
        $run = WorkspaceMigrationRun::where('mode', 'apply')->sole();
        $this->assertSame('failed', $run->state);
        $this->assertArrayHasKey('root:classrooms', $run->checkpoints);
        $this->assertSame('provisioning', Workspace::sole()->status->value);
        $result = $this->applyManifest($manifest);
        $this->assertFalse($result['no_op']);
        $this->assertSame($run->id, $result['ledger_id']);
        $this->assertSame(2, $run->fresh()->attempts);
        $this->assertSame('completed', $run->fresh()->state);
        $this->assertCount(1, $run->fresh()->history);
        $this->assertSame(1, Workspace::count());
        $this->assertSame(1, DB::table('workspace_managers')->count());
    }

    public function test_scoped_writers_inherit_parents_ignore_forged_input_and_associate_new_accounts_without_roles(): void
    {
        [$admin, , $teachers] = $this->schoolFixture();
        $this->applyManifest($this->manifest($admin));
        $workspace = Workspace::sole();
        $classroom = Classroom::factory()->create(['teacher_id' => $teachers[0]->id]);
        $assignment = new Assignment(['classroom_id' => $classroom->id, 'created_by' => $teachers[0]->id, 'title' => 'Synthetic new work']);
        $assignment->forceFill(['workspace_id' => 999999])->save();
        $this->assertSame($workspace->id, $assignment->fresh()->workspace_id);
        $this->assertArrayNotHasKey('workspace_id', $assignment->toArray());
        $year = LegacyWorkspaceScope::insert('academic_years', ['name' => 'Synthetic compatible year', 'starts_on' => '2030-01-01', 'ends_on' => '2031-01-01', 'workspace_id' => 999999]);
        $this->assertSame($workspace->id, (int) DB::table('academic_years')->where('id', $year)->value('workspace_id'));
        $newStudent = $this->student();
        $this->assertSame('student', $newStudent->fresh()->role);
        $this->assertTrue($newStudent->workspaceMemberships()->where('workspace_id', $workspace->id)->exists());
        $this->assertFalse($workspace->canBeConfiguredBy($newStudent));
    }

    public function test_populated_scope_or_ledger_prevents_destructive_schema_rollback(): void
    {
        $this->applyManifest($this->manifest($this->admin()));
        $migration = require database_path('migrations/2026_10_17_000002_add_compatibility_workspace_scope.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_empty_scope_migration_rolls_back_and_reapplies_preserving_existing_triggers_and_rows(): void
    {
        [$admin] = $this->schoolFixture();
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_17_000002_add_compatibility_workspace_scope.php');
        [$legacy, $teacher, $student] = $this->classWithMember();
        $this->publishedQuiz($legacy, $teacher);
        Assignment::create(['classroom_id' => $legacy->id, 'created_by' => $teacher->id, 'title' => 'Retain rollback work']);
        $tables = ['users', 'classrooms', 'memberships', 'school_modules', 'module_offerings', 'school_enrollments', 'quizzes', 'questions', 'options', 'assignments'];
        $snapshot = function () use ($tables): array {
            $records = [];
            foreach ($tables as $table) {
                $records[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                    $attributes = (array) $row;
                    unset($attributes['workspace_id']);
                    ksort($attributes);

                    return $attributes;
                })->all();
            }

            return $records;
        };
        $before = $snapshot();
        $triggers = DB::getDriverName() === 'sqlite' ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'school_%')->orderBy('name')->pluck('sql', 'name')->all() : [];
        $migration->down();
        $this->assertFalse(Schema::hasColumn('classrooms', 'workspace_id'));
        $migration->up();
        $this->assertSame($before, $snapshot());
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($triggers, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'school_%')->orderBy('name')->pluck('sql', 'name')->all());
        }
        $this->assertSame(0, Workspace::count());
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_explicit_owner_requires_reviewed_identity_and_does_not_change_global_role(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $manifest = $this->manifest($admin);
        $manifest['owner'] = ['user_id' => $teacher->id, 'identity_sha256' => hash('sha256', mb_strtolower($teacher->email))];
        $this->applyManifest($manifest);
        $this->assertSame($teacher->id, Workspace::sole()->ownerMembership->user_id);
        $this->assertFalse($teacher->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_post_bootstrap_institutional_writes_and_event_context_use_authoritative_roots(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $this->applyManifest($this->manifest($admin));
        $workspace = Workspace::sole();
        $newTeacher = $this->teacher();
        $newStudent = $this->student();
        $setup = app(SchoolSetupService::class);
        $assignment = $setup->assign($admin, $offerings[0], $newTeacher);
        $setup->enroll($admin, $group, $newStudent);
        $delegate = $setup->appoint($admin, $group, $newStudent, now()->addMonth()->toIso8601String());
        $this->assertSame($workspace->id, LegacyWorkspaceScope::resolve('teaching_assignments', $assignment));
        $this->assertSame($workspace->id, LegacyWorkspaceScope::resolve('class_delegates', $delegate));
        $assessment = app(OfficialGradeService::class)->create($teachers[0], $offerings[0], ['title' => 'New scoped assessment', 'type' => 'exam', 'assessed_on' => now()->toDateString(), 'maximum_score' => 20, 'coefficient' => 1]);
        $this->assertSame($workspace->id, LegacyWorkspaceScope::resolve('official_assessments', $assessment));
        $thread = app(SchoolCommunicationService::class)->create($students[0], ['kind' => 'private_question', 'classroom_id' => $group->id, 'offering_id' => $offerings[0], 'subject' => 'Scoped message', 'body' => 'Synthetic private text', 'participant_ids' => [$teachers[0]->id]]);
        $this->assertSame($workspace->id, LegacyWorkspaceScope::resolve('school_threads', $thread));
        $this->assertTrue(DB::table('school_outbox_scopes')->where('workspace_id', $workspace->id)->exists());
        $this->assertTrue(DB::table('audit_event_scopes')->where('workspace_id', $workspace->id)->exists());
        $resource = new Material(['classroom_id' => $group->id, 'uploaded_by' => $teachers[0]->id, 'title' => 'Scoped resource', 'type' => 'link', 'path_or_url' => 'https://example.invalid/resource']);
        $resource->forceFill(['offering_id' => $offerings[0]])->save();
        $this->assertSame($workspace->id, $resource->fresh()->workspace_id);
        $this->asToken($this->tokenFor($admin))->postJson('/api/school/years', ['name' => 'Scoped academic year', 'starts_on' => '2030-01-01', 'ends_on' => '2031-01-01'])->assertCreated();
        $this->assertSame(0, DB::table('academic_years')->whereNull('workspace_id')->count());
        $this->postJson('/api/school/modules', ['code' => 'SYN-NEW', 'name' => 'Scoped module'])->assertCreated();
        $this->assertSame(0, DB::table('school_modules')->whereNull('workspace_id')->count());
        $school = $this->getJson('/api/school')->assertOk()->json();
        $this->assertArrayNotHasKey('workspace_id', $school['years'][0]);
        $this->assertArrayNotHasKey('workspace_id', $school['modules'][0]);
        $publicThread = $this->asToken($this->tokenFor($students[0]))->getJson('/api/school/threads/'.$thread)->assertOk()->json('thread');
        $this->assertArrayNotHasKey('workspace_id', $publicThread);
    }

    public function test_conflicting_official_content_and_changed_snapshot_are_reported_not_repaired(): void
    {
        [$admin, $group, $teachers] = $this->schoolFixture();
        DB::table('assignments')->insert(['classroom_id' => $group->id, 'created_by' => $teachers[0]->id, 'title' => 'Missing offering provenance']);
        $manifest = $this->manifest($admin);
        $dry = app(OfpptCompatibilityBootstrap::class)->dryRun($manifest);
        $this->assertGreaterThan(0, $dry['conflict_count']);
        $this->assertSame('official_content_without_offering', $dry['conflicts'][0]['code']);
        try {
            $this->applyManifest($manifest);
            $this->fail('Ambiguous official content was backfilled.');
        } catch (ValidationException) {
            $this->assertSame(0, Workspace::count());
        }
    }

    public function test_cli_defaults_to_inspection_and_explicit_gates_allow_only_reviewed_apply(): void
    {
        $manifest = $this->manifest($this->admin());
        $path = tempnam(storage_path('framework/cache'), 'classlink-b2-manifest-');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $reports = [];
        try {
            $this->assertSame(0, Artisan::call('classlink:ofppt-compatibility', ['--manifest' => $path]));
            $output = Artisan::output();
            preg_match('/Private report: (.+)/', $output, $matches);
            $reports[] = trim($matches[1]);
            $this->assertSame(0, Workspace::count());
            $this->assertStringNotContainsString('admin.classlink@', $output);
            $dry = json_decode(file_get_contents($reports[0]), true, 32, JSON_THROW_ON_ERROR);
            $options = ['--manifest' => $path, '--apply' => true, '--confirm-manifest' => $dry['manifest_hash'], '--confirm-target' => $dry['target_hash'], '--confirm-inventory' => $dry['inventory_hash'], '--writers-quiesced' => true];
            config(['workspace_compatibility.apply_enabled' => false]);
            $this->assertSame(1, Artisan::call('classlink:ofppt-compatibility', $options));
            preg_match('/Private report: (.+)/', Artisan::output(), $matches);
            $reports[] = trim($matches[1]);
            $this->assertSame(0, Workspace::count());
            config(['workspace_compatibility.apply_enabled' => true]);
            $this->assertSame(0, Artisan::call('classlink:ofppt-compatibility', $options));
            preg_match('/Private report: (.+)/', Artisan::output(), $matches);
            $reports[] = trim($matches[1]);
            $this->assertSame(1, Workspace::count());
        } finally {
            unlink($path);
            foreach ($reports as $report) {
                unlink($report);
            }
        }
    }
}
