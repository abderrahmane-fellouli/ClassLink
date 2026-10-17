<?php

namespace App\Workspace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Persisted source graph only: never browser workspace state or recipient membership. */
final class WorkspaceEventContext
{
    public const TABLES = ['audit_logs' => ['audit_event_scopes', 'audit_id'], 'notifications' => ['notification_scopes', 'notification_id'], 'school_notification_outbox' => ['school_outbox_scopes', 'outbox_id']];

    public static function infer(string $table, object $record): array
    {
        $audit = $table === 'audit_logs';
        $type = $audit ? $record->action : $record->type;
        if ($audit && (str_starts_with($type, 'auth.') || str_starts_with($type, 'user.') || str_starts_with($type, 'ai.provider'))) {
            return ['classification' => 'platform', 'workspace_id' => null];
        }
        $payload = $audit ? $record->context : $record->payload;
        $payload = is_array($payload) ? $payload : (json_decode($payload ?? '{}', true) ?: []);
        $keys = ['classroom_id' => 'classrooms', 'offering_id' => 'module_offerings', 'assessment_id' => 'official_assessments', 'quiz_id' => 'quizzes', 'thread_id' => 'school_threads', 'job_id' => 'ai_jobs', 'membership_id' => 'memberships', 'submission_id' => 'submissions', 'deck_id' => 'flashcard_decks', 'delegate_id' => 'class_delegates'];
        $keys['assignment_id'] = $audit && str_starts_with($type, 'school.assignment.') ? 'teaching_assignments' : 'assignments';
        $keys += ['year_id' => 'academic_years', 'module_id' => 'school_modules', 'report_id' => 'school_reports', 'request_id' => 'school_assignment_requests'];
        $scopes = [];
        foreach ($keys as $key => $source) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $scope = LegacyWorkspaceScope::resolve($source, (int) $payload[$key]);
                if ($scope !== null) {
                    $scopes[] = $scope;
                }
            }
        }
        foreach (($payload['thread_ids'] ?? []) as $id) {
            if (($scope = LegacyWorkspaceScope::resolve('school_threads', (int) $id)) !== null) {
                $scopes[] = $scope;
            }
        }
        if (count(array_unique($scopes)) > 1) {
            throw new RuntimeException('conflicting_event_roots');
        }

        return $scopes ? ['classification' => 'workspace', 'workspace_id' => $scopes[0]] : ['classification' => 'unresolved', 'workspace_id' => null];
    }

    public static function persist(string $table, object $record, bool $includeUnresolved = false): void
    {
        [$scopeTable, $key] = self::TABLES[$table];
        if (! Schema::hasTable($scopeTable)) {
            return;
        }
        if (! $includeUnresolved && LegacyWorkspaceScope::compatibilityId() === null) {
            return; // Dormant before bootstrap; no new auth/notification side effects.
        }
        $scope = self::infer($table, $record);
        if ($scope['classification'] === 'unresolved' && ! $includeUnresolved) {
            return; // Leave pre-backfill events for authoritative bootstrap classification.
        }
        $existing = DB::table($scopeTable)->where($key, $record->id)->first();
        if ($existing) {
            if ($existing->classification !== $scope['classification'] || ($existing->workspace_id === null ? null : (int) $existing->workspace_id) !== $scope['workspace_id']) {
                throw new RuntimeException('event_scope_conflict');
            }

            return;
        }
        DB::table($scopeTable)->insert([$key => $record->id] + $scope + ['recorded_at' => now()]);
    }

    public static function insertOutbox(array $rows): void
    {
        if (isset($rows['event_key'])) {
            $rows = [$rows];
        }
        DB::transaction(function () use ($rows) {
            DB::table('school_notification_outbox')->insertOrIgnore($rows);
            foreach ($rows as $row) {
                $record = DB::table('school_notification_outbox')->where('event_key', $row['event_key'])->where('user_id', $row['user_id'])->first();
                self::persist('school_notification_outbox', $record);
            }
        });
    }
}
