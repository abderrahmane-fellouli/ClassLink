<?php

namespace App\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Writer metadata only. No tenant global scope, role change or read filtering. */
final class LegacyWorkspaceScope
{
    public const ROOTS = ['academic_years', 'classrooms', 'school_modules', 'assignments', 'quizzes', 'flashcard_decks', 'announcements', 'materials', 'ai_jobs', 'school_threads', 'school_assignment_requests'];

    public const INHERITED = [
        'module_offerings' => ['classroom_id', 'classrooms'],
        'teaching_assignments' => ['offering_id', 'module_offerings'],
        'school_enrollments' => ['classroom_id', 'classrooms'],
        'memberships' => ['classroom_id', 'classrooms'],
        'class_delegates' => ['classroom_id', 'classrooms'],
        'submissions' => ['assignment_id', 'assignments'],
        'attempts' => ['quiz_id', 'quizzes'],
        'questions' => ['quiz_id', 'quizzes'],
        'options' => ['question_id', 'questions'],
        'attempt_answers' => ['attempt_id', 'attempts'],
        'flashcards' => ['deck_id', 'flashcard_decks'],
        'flashcard_reviews' => ['flashcard_id', 'flashcards'],
        'official_assessments' => ['offering_id', 'module_offerings'],
        'assessment_candidates' => ['assessment_id', 'official_assessments'],
        'grade_revisions' => ['assessment_id', 'official_assessments'],
        'grade_entries' => ['assessment_id', 'official_assessments'],
        'grade_import_batches' => ['assessment_id', 'official_assessments'],
        'roster_import_batches' => ['classroom_id', 'classrooms'],
        'school_messages' => ['thread_id', 'school_threads'],
        'school_thread_participants' => ['thread_id', 'school_threads'],
        'school_reports' => ['thread_id', 'school_threads'],
        'module_access_grants' => ['offering_id', 'module_offerings'],
        'partner_requests' => ['classroom_id', 'classrooms'],
    ];

    public static function compatibilityId(): ?int
    {
        if (! Schema::hasTable('workspaces')) {
            return null;
        }
        $id = DB::table('workspaces')->where('public_key', Workspace::COMPATIBILITY_KEY)->where('kind', 'institutional')->where('status', 'active')->value('id');

        return $id === null ? null : (int) $id;
    }

    public static function parents(string $table): array
    {
        return match ($table) {
            'classrooms' => ['academic_year_id' => 'academic_years'],
            'assignments', 'quizzes', 'flashcard_decks', 'announcements', 'materials' => ['classroom_id' => 'classrooms', 'offering_id' => 'module_offerings'],
            'ai_jobs' => ['classroom_id' => 'classrooms'],
            'school_threads' => ['classroom_id' => 'classrooms', 'offering_id' => 'module_offerings', 'assessment_id' => 'official_assessments'],
            'school_assignment_requests' => ['offering_id' => 'module_offerings'],
            default => [],
        };
    }

    public static function resolve(string $table, int $id, bool $strict = false): ?int
    {
        if (in_array($table, self::ROOTS, true)) {
            if (! Schema::hasColumn($table, 'workspace_id')) {
                return null;
            }
            $row = DB::table($table)->where('id', $id)->first();
            if (! $row && $strict) {
                throw new RuntimeException('missing_parent:'.$table);
            }

            return isset($row->workspace_id) ? (int) $row->workspace_id : null;
        }
        [$key, $parent] = self::INHERITED[$table] ?? throw new RuntimeException('unknown_scope_domain');
        $parentId = DB::table($table)->where('id', $id)->value($key);
        if (! $parentId) {
            if ($strict) {
                throw new RuntimeException('missing_parent:'.$table);
            }

            return null;
        }

        return self::resolve($parent, (int) $parentId, $strict);
    }

    public static function stamp(string $table, array $attributes): array
    {
        if (! Schema::hasColumn($table, 'workspace_id')) {
            unset($attributes['workspace_id']);

            return $attributes;
        }
        $compatibility = self::compatibilityId();
        $parents = self::parents($table);
        $scopes = [];
        $hasParent = false;
        foreach ($parents as $field => $parent) {
            if (! empty($attributes[$field])) {
                $hasParent = true;
                $scope = self::resolve($parent, (int) $attributes[$field], $compatibility !== null);
                if ($scope !== null) {
                    $scopes[] = $scope;
                } elseif ($compatibility !== null) {
                    throw new RuntimeException('unscoped_parent:'.$parent);
                }
            }
        }
        if (count(array_unique($scopes)) > 1) {
            throw new RuntimeException('conflicting_parent_scope');
        }
        // Client/body workspace_id is never a source of authority.
        $attributes['workspace_id'] = $hasParent ? ($scopes[0] ?? null) : $compatibility;

        return $attributes;
    }

    public static function insert(string $table, array $attributes): int
    {
        return DB::table($table)->insertGetId(self::stamp($table, $attributes));
    }

    public static function insertOffering(array $attributes): int
    {
        $group = self::resolve('classrooms', (int) $attributes['classroom_id']);
        $module = self::resolve('school_modules', (int) $attributes['module_id']);
        if (($group !== null && $module !== null && $group !== $module) || (self::compatibilityId() !== null && ($group === null || $module === null))) {
            throw new RuntimeException('offering_parent_scope_conflict');
        }

        return DB::table('module_offerings')->insertGetId($attributes);
    }

    /** Preserve existing raw-query JSON contracts without filtering any rows. */
    public static function publicRecord(object $record): object
    {
        $public = clone $record;
        unset($public->workspace_id);

        return $public;
    }

    public static function associateUser(User $user): void
    {
        if (($id = self::compatibilityId()) !== null) {
            DB::table('workspace_memberships')->insertOrIgnore(['workspace_id' => $id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
