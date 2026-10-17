<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceManager;
use App\Models\WorkspaceMigrationRun;
use App\Workspace\Capability;
use App\Workspace\CapabilityRegistry;
use App\Workspace\LegacyWorkspaceScope;
use App\Workspace\WorkspaceEventContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Internal, manifest-bound cutover tooling. No route, read filter or automatic execution. */
class OfpptCompatibilityBootstrap
{
    public const OPERATION = 'ofppt-compatibility';

    public function targetHash(): string
    {
        $driver = DB::getDriverName();
        $connection = DB::connection()->getConfig();

        return hash('sha256', json_encode([app()->environment(), $driver, $connection['host'] ?? null, $connection['port'] ?? null, DB::connection()->getDatabaseName()], JSON_THROW_ON_ERROR));
    }

    public function manifestHash(array $manifest): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($manifest), JSON_THROW_ON_ERROR));
    }

    private function invalid(string $code): never
    {
        throw ValidationException::withMessages(['compatibility' => $code]);
    }

    private function validateManifest(array $manifest): void
    {
        if (count(Capability::cases()) !== 26 || count(array_filter(Capability::cases(), CapabilityRegistry::isImplemented(...))) !== 22) {
            $this->invalid('registry_snapshot_changed_requires_operation_version_review');
        }
        if (array_diff(array_keys($manifest), ['operation', 'version', 'environment', 'target_hash', 'approval_reference', 'actor', 'managers', 'owner', 'allow_demo_managers'])
            || ($manifest['operation'] ?? null) !== self::OPERATION || ($manifest['version'] ?? null) !== 1
            || ($manifest['environment'] ?? null) !== app()->environment() || ($manifest['target_hash'] ?? null) !== $this->targetHash()
            || ! is_string($manifest['approval_reference'] ?? null) || ! preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $manifest['approval_reference'])
            || (isset($manifest['allow_demo_managers']) && ! is_bool($manifest['allow_demo_managers']))
            || ! is_array($manifest['managers'] ?? null) || ! array_is_list($manifest['managers']) || ! count($manifest['managers']) || count($manifest['managers']) > 100) {
            $this->invalid('invalid_reviewed_manifest');
        }
        $local = app()->environment(['local', 'testing']) && (DB::getDriverName() === 'sqlite'
            || (in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true) && empty(config('database.connections.pgsql.url'))));
        if (($manifest['allow_demo_managers'] ?? false) && ! $local) {
            $this->invalid('demo_managers_are_local_only');
        }
        $ids = [];
        foreach (array_merge([$manifest['actor'] ?? null], $manifest['managers'], isset($manifest['owner']) ? [$manifest['owner']] : []) as $index => $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['user_id', 'identity_sha256']) || ! is_int($entry['user_id'] ?? null) || ! is_string($entry['identity_sha256'] ?? null)) {
                $this->invalid('invalid_identity_reference');
            }
            $user = User::find($entry['user_id']);
            $ownerEntry = isset($manifest['owner']) && $index === count($manifest['managers']) + 1;
            if (! $user || ! $user->is_active || ! $user->role_locked || (! $user->isAdmin() && ! ($ownerEntry && $user->isTeacher()))
                || ! hash_equals(hash('sha256', mb_strtolower(trim($user->email))), $entry['identity_sha256'])) {
                $this->invalid('identity_or_eligibility_mismatch');
            }
            if (mb_strtolower($user->email) === 'admin.classlink@ofppt-edu.ma' && ! ($local && ($manifest['allow_demo_managers'] ?? false))) {
                $this->invalid('demo_identity_requires_local_acknowledgement');
            }
            if ($index > 0 && $index <= count($manifest['managers'])) {
                if (in_array($user->id, $ids, true)) {
                    $this->invalid('duplicate_manager');
                }
                $ids[] = $user->id;
            }
        }
    }

    public function inspect(array $manifest): array
    {
        $this->validateManifest($manifest);
        $workspace = DB::table('workspaces')->where('public_key', Workspace::COMPATIBILITY_KEY)->first();
        $conflicts = [];
        $counts = [];
        $inventory = hash_init('sha256');
        if (DB::table('workspaces')->where('public_key', '!=', Workspace::COMPATIBILITY_KEY)->exists()) {
            $conflicts[] = ['code' => 'independent_workspaces_present'];
        }
        if ($workspace && ($workspace->kind !== 'institutional' || $workspace->timezone !== 'Africa/Casablanca' || ! in_array($workspace->status, ['provisioning', 'active'], true) || $workspace->ownership_state === 'recovery_required')) {
            $conflicts[] = ['code' => 'compatibility_workspace_state_conflict'];
        }
        foreach (LegacyWorkspaceScope::ROOTS as $table) {
            $counts[$table] = ['rows' => DB::table($table)->count(), 'unscoped' => DB::table($table)->whereNull('workspace_id')->count()];
            DB::table($table)->orderBy('id')->chunkById(250, function ($rows) use ($table, $workspace, &$conflicts, $inventory) {
                foreach ($rows as $row) {
                    $parents = [];
                    foreach (LegacyWorkspaceScope::parents($table) as $key => $parent) {
                        $parents[$key] = $row->$key ?? null;
                        if ($parents[$key] !== null) {
                            try {
                                LegacyWorkspaceScope::resolve($parent, (int) $parents[$key], true);
                            } catch (RuntimeException) {
                                $conflicts[] = ['table' => $table, 'id' => $row->id, 'code' => 'missing_parent'];
                            }
                        }
                    }
                    if ($row->workspace_id !== null && (! $workspace || (int) $row->workspace_id !== (int) $workspace->id)) {
                        $conflicts[] = ['table' => $table, 'id' => $row->id, 'code' => 'foreign_scope'];
                    }
                    if (isset($row->offering_id, $row->classroom_id) && DB::table('module_offerings')->where('id', $row->offering_id)->value('classroom_id') != $row->classroom_id) {
                        $conflicts[] = ['table' => $table, 'id' => $row->id, 'code' => 'offering_group_mismatch'];
                    }
                    if (in_array($table, ['assignments', 'quizzes', 'flashcard_decks', 'announcements', 'materials'], true) && empty($row->offering_id) && DB::table('classrooms')->where('id', $row->classroom_id)->where('is_official', true)->exists()) {
                        $conflicts[] = ['table' => $table, 'id' => $row->id, 'code' => 'official_content_without_offering'];
                    }
                    if ($table === 'school_threads' && $row->classroom_id === null && $row->kind !== 'class_notice') {
                        $conflicts[] = ['table' => $table, 'id' => $row->id, 'code' => 'unproven_thread_root'];
                    }
                    hash_update($inventory, json_encode([$table, $row->id, $parents], JSON_THROW_ON_ERROR));
                }
            });
        }
        foreach (LegacyWorkspaceScope::INHERITED as $table => [$key, $parent]) {
            $missing = DB::table($table.' as child')->leftJoin($parent.' as parent', 'parent.id', '=', 'child.'.$key)->whereNull('parent.id')->count();
            if ($missing) {
                $conflicts[] = ['table' => $table, 'code' => 'orphan_parent', 'count' => $missing];
            }
            DB::table($table)->select(['id', $key])->orderBy('id')->chunkById(250, function ($rows) use ($table, $key, $inventory) {
                foreach ($rows as $row) {
                    hash_update($inventory, json_encode([$table, $row->id, $row->$key], JSON_THROW_ON_ERROR));
                }
            });
        }
        foreach (DB::table('module_offerings')->get() as $offering) {
            $group = DB::table('classrooms')->where('id', $offering->classroom_id)->first();
            $module = DB::table('school_modules')->where('id', $offering->module_id)->first();
            if (! $group || ! $module || ($group->workspace_id !== null && $module->workspace_id !== null && $group->workspace_id != $module->workspace_id)) {
                $conflicts[] = ['table' => 'module_offerings', 'code' => 'invalid_module_parent'];
            }
        }
        DB::table('users')->orderBy('id')->chunkById(250, function ($users) use ($inventory) {
            foreach ($users as $user) {
                hash_update($inventory, json_encode([$user->id, $user->role, (bool) $user->is_active, (bool) $user->role_locked], JSON_THROW_ON_ERROR));
            }
        });
        $counts['memberships_to_create'] = $workspace ? DB::table('users')->whereNotIn('id', DB::table('workspace_memberships')->where('workspace_id', $workspace->id)->select('user_id'))->count() : DB::table('users')->count();
        $counts['configuration_to_create'] = $workspace && DB::table('workspace_configurations')->where('workspace_id', $workspace->id)->exists() ? 0 : 1;
        $counts['onboarding_to_create'] = $workspace && DB::table('workspace_onboardings')->where('workspace_id', $workspace->id)->exists() ? 0 : 1;
        $counts['capabilities_to_create'] = count(Capability::cases()) - ($workspace ? DB::table('workspace_capabilities')->where('workspace_id', $workspace->id)->count() : 0);
        $existingManagers = $workspace ? DB::table('workspace_managers as g')->join('workspace_memberships as m', 'm.id', '=', 'g.membership_id')->where('m.workspace_id', $workspace->id)->whereNull('g.revoked_at')->pluck('m.user_id')->map(fn ($id) => (int) $id)->all() : [];
        $counts['manager_grants_to_create'] = count(array_diff(array_column($manifest['managers'], 'user_id'), $existingManagers));
        if (array_diff($existingManagers, array_column($manifest['managers'], 'user_id'))) {
            $conflicts[] = ['code' => 'unreviewed_existing_manager'];
        }

        return ['operation' => self::OPERATION, 'version' => 1, 'manifest_hash' => $this->manifestHash($manifest), 'target_hash' => $this->targetHash(), 'inventory_hash' => hash_final($inventory), 'workspace' => $workspace ? 'reuse' : 'create', 'counts' => $counts, 'manager_count' => count($manifest['managers']), 'conflict_count' => count($conflicts), 'conflicts' => array_slice($conflicts, 0, 50)];
    }

    public function dryRun(array $manifest): array
    {
        $report = $this->inspect($manifest);
        $run = $this->run($report, 'dry_run');
        $run->update(['state' => 'completed', 'stage' => 'inspection', 'completed_at' => now(), 'counts' => $report['counts']]);

        return $report + ['mode' => 'dry_run', 'domain_writes' => false, 'ledger_id' => $run->id];
    }

    private function run(array $report, string $mode): WorkspaceMigrationRun
    {
        return WorkspaceMigrationRun::firstOrCreate(['operation' => self::OPERATION, 'version' => 1, 'manifest_hash' => $report['manifest_hash'], 'mode' => $mode], ['target_hash' => $report['target_hash'], 'inventory_hash' => $report['inventory_hash'], 'state' => 'running', 'stage' => 'inspection', 'counts' => [], 'checkpoints' => [], 'history' => [], 'started_at' => now()]);
    }

    /** Fault-injection seam for isolated tests, never selected by CLI/input/manifest. */
    protected function afterStage(string $stage): void {}

    public function apply(array $manifest, string $confirmedHash, string $confirmedTarget, string $confirmedInventory, bool $writersQuiesced): array
    {
        if (! config('workspace_compatibility.apply_enabled') || ! $writersQuiesced || ! hash_equals($this->manifestHash($manifest), $confirmedHash) || ! hash_equals($this->targetHash(), $confirmedTarget)) {
            $this->invalid('apply_gate_closed');
        }
        $lock = null;
        if (DB::getDriverName() === 'pgsql') {
            if (! DB::selectOne('SELECT pg_try_advisory_lock(1845032107) AS acquired')->acquired) {
                $this->invalid('operation_already_running');
            }
        } else {
            $lock = fopen(storage_path('framework/cache/ofppt-'.$this->targetHash().'.lock'), 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->invalid('operation_already_running');
            }
        }
        $run = null;
        try {
            $report = $this->inspect($manifest);
            if ($report['conflict_count'] || ! hash_equals($report['inventory_hash'], $confirmedInventory)) {
                $this->invalid('conflicts_or_stale_inventory');
            }
            $run = $this->run($report, 'apply');
            $completed = $run->state === 'completed';
            if ($completed) {
                $this->verifySeed($manifest);
                if (array_sum(array_map(fn ($row) => is_array($row) ? ($row['unscoped'] ?? 0) : 0, $report['counts'])) || $report['counts']['memberships_to_create']) {
                    $this->invalid('completed_operation_has_new_unscoped_data');
                }

                return $report + ['mode' => 'apply', 'ledger_id' => $run->id, 'no_op' => true];
            }
            if (! hash_equals($run->inventory_hash, $report['inventory_hash'])) {
                $this->invalid('resume_inventory_changed_requires_new_review');
            }
            $run->update(['state' => 'running', 'completed_at' => null, 'failed_at' => null, 'failure_code' => null, 'attempts' => $run->wasRecentlyCreated ? 1 : $run->attempts + 1]);
            $this->stage($run, 'bootstrap', function () use ($manifest) {
                $this->seed($manifest);
            });
            $workspace = Workspace::where('public_key', Workspace::COMPATIBILITY_KEY)->sole();
            foreach (LegacyWorkspaceScope::ROOTS as $table) {
                $this->stage($run, 'root:'.$table, function () use ($workspace, $table) {
                    $changed = 0;
                    DB::table($table)->whereNull('workspace_id')->orderBy('id')->chunkById(250, function ($rows) use ($table, $workspace, &$changed) {
                        $changed += DB::table($table)->whereIn('id', $rows->pluck('id'))->whereNull('workspace_id')->update(['workspace_id' => $workspace->id]);
                    });

                    return $changed;
                });
            }
            foreach (WorkspaceEventContext::TABLES as $source => $_) {
                $this->stage($run, 'event:'.$source, function () use ($source) {
                    DB::table($source)->orderBy('id')->chunkById(250, function ($rows) use ($source) {
                        foreach ($rows as $row) {
                            WorkspaceEventContext::persist($source, $row, true);
                        }
                    });
                });
            }
            DB::transaction(function () use ($run, $workspace, $manifest) {
                foreach (LegacyWorkspaceScope::ROOTS as $table) {
                    if (DB::table($table)->whereNull('workspace_id')->orWhere('workspace_id', '!=', $workspace->id)->exists()) {
                        throw new RuntimeException('scope_reconciliation_failed');
                    }
                }
                $final = $this->inspect($manifest);
                if ($final['conflict_count'] || $final['counts']['memberships_to_create'] || ! hash_equals($run->inventory_hash, $final['inventory_hash'])) {
                    throw new RuntimeException('cutover_inventory_changed');
                }
                if ($workspace->status->value !== 'active') {
                    $workspace->update(['status' => 'active']);
                }
                $this->verifySeed($manifest);
                $run->update(['state' => 'completed', 'stage' => 'verified', 'completed_at' => now()]);
            });

            return $this->inspect($manifest) + ['mode' => 'apply', 'ledger_id' => $run->id, 'no_op' => $completed];
        } catch (Throwable $error) {
            if ($run) {
                $run->refresh();
                $history = $run->history;
                $history[] = ['attempt' => $run->attempts, 'stage' => $run->stage, 'failed_at' => now()->toIso8601String(), 'code' => 'stage_failed'];
                $run->update(['state' => 'failed', 'failed_at' => now(), 'failure_code' => 'stage_failed', 'history' => $history]);
            }
            throw $error;
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_unlock(1845032107)');
            } elseif ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function stage(WorkspaceMigrationRun $run, string $stage, callable $work): void
    {
        $run->update(['stage' => $stage]);
        DB::transaction(function () use ($run, $stage, $work) {
            $changed = $work() ?? 0;
            $checkpoints = $run->checkpoints;
            $checkpoints[$stage] ??= ['completed_at' => now()->toIso8601String(), 'rows_changed' => $changed];
            if (str_starts_with($stage, 'root:')) {
                $checkpoints[$stage]['last_id'] = DB::table(substr($stage, 5))->max('id');
            }
            $counts = $run->counts;
            $counts[$stage] = ($counts[$stage] ?? 0) + $changed;
            $run->update(['checkpoints' => $checkpoints, 'counts' => $counts]);
        });
        $this->afterStage($stage);
    }

    private function seed(array $manifest): void
    {
        User::whereIn('id', array_column(array_merge([$manifest['actor']], $manifest['managers'], isset($manifest['owner']) ? [$manifest['owner']] : []), 'user_id'))->lockForUpdate()->get();
        $this->validateManifest($manifest);
        $workspace = Workspace::firstOrCreate(['public_key' => Workspace::COMPATIBILITY_KEY], ['name' => 'ClassLink — OFPPT', 'kind' => 'institutional', 'timezone' => 'Africa/Casablanca', 'ownership_state' => 'unassigned_compatibility']);
        if ($workspace->kind->value !== 'institutional' || $workspace->timezone !== 'Africa/Casablanca' || $workspace->ownership_state->value === 'recovery_required' || ! in_array($workspace->status->value, ['provisioning', 'active'], true)) {
            throw new RuntimeException('workspace_configuration_conflict');
        }
        DB::table('users')->orderBy('id')->chunkById(250, function ($users) use ($workspace) {
            foreach ($users as $user) {
                if (! DB::table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->exists()) {
                    DB::table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        $ownerId = isset($manifest['owner']) ? DB::table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', $manifest['owner']['user_id'])->value('id') : null;
        if ($workspace->owner_membership_id !== null && (int) $workspace->owner_membership_id !== $ownerId) {
            throw new RuntimeException('explicit_owner_conflict');
        }
        if ($ownerId !== null && $workspace->owner_membership_id === null) {
            $workspace->update(['ownership_state' => 'owned', 'owner_membership_id' => $ownerId]);
        }
        $configuration = $workspace->configuration()->firstOrCreate([], ['preset_id' => 'ofppt', 'terminology_preset_id' => 'ofppt']);
        if ($configuration->preset_id->value !== 'ofppt' || $configuration->terminology_preset_id->value !== 'ofppt' || $configuration->revision !== 1 || $configuration->preset_version !== 1 || $configuration->terminology_version !== 1 || $configuration->preferences || $configuration->terminology_overrides) {
            throw new RuntimeException('configuration_snapshot_conflict');
        }
        foreach (Capability::cases() as $capability) {
            $state = $workspace->capabilities()->firstOrCreate(['capability' => $capability], ['enabled' => CapabilityRegistry::isImplemented($capability)]);
            if ($state->enabled !== CapabilityRegistry::isImplemented($capability)) {
                throw new RuntimeException('capability_snapshot_conflict');
            }
        }
        $onboarding = $workspace->onboarding()->firstOrCreate([], ['status' => 'completed', 'version' => 1, 'completion_origin' => 'migration', 'completed_at' => now(), 'draft_answers' => []]);
        if ($onboarding->status->value !== 'completed' || $onboarding->completion_origin !== 'migration' || $onboarding->version !== 1) {
            throw new RuntimeException('onboarding_snapshot_conflict');
        }
        foreach ($manifest['managers'] as $entry) {
            $member = $workspace->memberships()->where('user_id', $entry['user_id'])->sole();
            if ($member->status->value !== 'active') {
                throw new RuntimeException('manager_membership_conflict');
            }
            $grant = $member->managerGrants()->whereNull('revoked_at')->first();
            if (! $grant && $member->managerGrants()->exists()) {
                throw new RuntimeException('revoked_manager_requires_new_designation');
            }
            if (! $grant) {
                WorkspaceManager::create(['membership_id' => $member->id, 'granted_by' => $manifest['actor']['user_id'], 'granted_at' => now()]);
            }
        }
    }

    private function verifySeed(array $manifest): void
    {
        $workspace = Workspace::where('public_key', Workspace::COMPATIBILITY_KEY)->sole();
        $config = $workspace->configuration;
        $onboarding = $workspace->onboarding;
        $owner = $workspace->ownerMembership?->user_id;
        if ($workspace->status->value !== 'active' || $owner !== ($manifest['owner']['user_id'] ?? null)
            || ! $config || $config->revision !== 1 || $config->preset_id->value !== 'ofppt' || $config->terminology_preset_id->value !== 'ofppt'
            || ! $onboarding || $onboarding->status->value !== 'completed' || $onboarding->completion_origin !== 'migration'
            || $workspace->capabilities()->count() !== count(Capability::cases())) {
            throw new RuntimeException('completed_snapshot_conflict');
        }
        foreach ($workspace->capabilities as $state) {
            if ($state->enabled !== CapabilityRegistry::isImplemented($state->capability)) {
                throw new RuntimeException('completed_capability_conflict');
            }
        }
        $grants = DB::table('workspace_managers as g')->join('workspace_memberships as m', 'm.id', '=', 'g.membership_id')->where('m.workspace_id', $workspace->id)->whereNull('g.revoked_at')->pluck('m.user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $expected = array_column($manifest['managers'], 'user_id');
        sort($expected);
        if ($grants !== $expected) {
            throw new RuntimeException('completed_manager_conflict');
        }
    }
}
