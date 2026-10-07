<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SchoolCommunicationService
{
    public function __construct(private SchoolAccess $access, private OfficialGradeService $grades) {}

    private function eligible(User $user, Classroom $group, string $eligibility, ?int $offering = null, ?int $assessment = null): bool
    {
        if (! $user->canAccessApp()) {
            return false;
        }

        return match ($eligibility) {
            'admin' => $user->isAdmin(),
            'delegate' => $this->access->delegate($user, $group),
            'student' => $this->access->enrolled($user, $group) || ($offering && $this->access->exceptional($user, $offering)),
            'grade_owner' => $user->isStudent() && $assessment && DB::table('assessment_candidates as c')->join('official_assessments as a', 'a.id', '=', 'c.assessment_id')->where('c.assessment_id', $assessment)->where('c.student_id', $user->id)->whereNotNull('a.published_revision_id')->exists(),
            'teacher' => $offering ? $this->access->teaches($user, $offering) : ($user->isTeacher() && ($this->access->canView($user, $group) || $this->access->rosterManager($user, $group))),
            default => false,
        };
    }

    public function canRead(User $actor, object $thread): bool
    {
        $participant = DB::table('school_thread_participants')->where('thread_id', $thread->id)->where('user_id', $actor->id)->first();
        if (! $thread->classroom_id) {
            return $participant && $thread->kind === 'class_notice' && $participant->eligibility === 'account' && $actor->canAccessApp();
        }

        return $participant && $this->eligible($actor, $this->access->group($thread->classroom_id), $participant->eligibility, $thread->offering_id, $thread->assessment_id);
    }

    public function thread(User $actor, int $id, bool $write = false): object
    {
        $thread = DB::table('school_threads')->find($id);
        abort_unless($thread && $this->canRead($actor, $thread), 404);
        if ($write) {
            if ($thread->classroom_id) {
                $this->access->writable($this->access->group($thread->classroom_id));
            }
            abort_unless($thread->status === 'open', 409);
            abort_if($thread->kind === 'class_notice' && $thread->created_by !== $actor->id, 403);
        }

        return $thread;
    }

    public function visibleThreads(User $actor)
    {
        $enrollments = DB::table('school_enrollments')->where('student_id', $actor->id)->whereNotNull('active_student_id')->whereNull('ends_at')->select('classroom_id');
        $grants = DB::table('module_access_grants')->where('student_id', $actor->id)->whereNull('revoked_at')->where('expires_at', '>', now())->select('offering_id');
        $assignments = DB::table('teaching_assignments')->where('teacher_id', $actor->id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->where('starts_at', '<=', now())->select('offering_id');
        $staffGroups = DB::table('module_offerings')->whereIn('id', clone $assignments)->select('classroom_id');

        return DB::table('school_threads as t')->join('school_thread_participants as p', 'p.thread_id', '=', 't.id')->where('p.user_id', $actor->id)
            ->where(function ($q) use ($actor, $enrollments, $grants, $assignments, $staffGroups) {
                $q->where(fn ($a) => $a->whereNull('t.classroom_id')->where('t.kind', 'class_notice')->where('p.eligibility', 'account'));
                if ($actor->isAdmin()) {
                    $q->orWhere('p.eligibility', 'admin');
                }
                if ($actor->isTeacher()) {
                    $q->orWhere(function ($s) use ($actor, $assignments, $staffGroups) {
                        $s->where('p.eligibility', 'teacher')->where(function ($scope) use ($actor, $assignments, $staffGroups) {
                            $scope->whereIn('t.offering_id', $assignments)->orWhere(function ($g) use ($actor, $staffGroups) {
                                $g->whereNull('t.offering_id')->where(function ($related) use ($actor, $staffGroups) {
                                    $related->whereIn('t.classroom_id', $staffGroups)->orWhereIn('t.classroom_id', DB::table('classrooms')->where('coordinator_id', $actor->id)->select('id'));
                                });
                            });
                        });
                    });
                }
                if ($actor->isStudent()) {
                    $q->orWhere(fn ($s) => $s->where('p.eligibility', 'student')->where(fn ($g) => $g->whereIn('t.classroom_id', clone $enrollments)->orWhereIn('t.offering_id', $grants)));
                    $q->orWhere(fn ($d) => $d->where('p.eligibility', 'delegate')->whereIn('t.classroom_id', clone $enrollments)
                        ->whereIn('t.classroom_id', DB::table('class_delegates')->where('student_id', $actor->id)->whereNotNull('active_slot')->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->select('classroom_id')));
                    $q->orWhere(fn ($g) => $g->where('p.eligibility', 'grade_owner')->whereIn('t.assessment_id', DB::table('assessment_candidates as c')->join('official_assessments as a', 'a.id', '=', 'c.assessment_id')->where('c.student_id', $actor->id)->whereNotNull('a.published_revision_id')->select('a.id')));
                }
            })->select('t.*', 'p.last_read_message_id')
            ->selectSub(DB::table('school_messages as m')->whereColumn('m.thread_id', 't.id')->selectRaw('MAX(m.id)'), 'latest_message_id')
            ->orderByDesc('t.updated_at')->orderByDesc('t.id');
    }

    public function create(User $actor, array $data): int
    {
        $group = $this->access->group($data['classroom_id']);
        $this->access->writable($group);
        $kind = $data['kind'];
        $offering = $data['offering_id'] ?? null;
        if (! empty($data['assessment_id'])) {
            $assessment = DB::table('official_assessments')->find($data['assessment_id']);
            abort_unless($assessment && $assessment->published_revision_id, 404);
            $offering = $assessment->offering_id;
        }
        if ($kind === 'private_question') {
            abort_unless($offering && DB::table('module_offerings')->where('id', $offering)->where('classroom_id', $group->id)->exists(), 422);
        }
        $ids = array_values(array_unique(array_merge([$actor->id], $data['participant_ids'])));
        abort_unless(count($ids) >= 2 && count($ids) <= 8, 422);
        $users = User::whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($users->count() === count($ids), 422);
        $roles = [];
        foreach ($users as $user) {
            $eligibility = $kind === 'delegate_contact' && $user->id === $actor->id ? 'student'
                : ($kind === 'organization' && $user->isAdmin() ? 'admin'
                    : (in_array($kind, ['representation', 'organization', 'delegate_contact']) && $this->access->delegate($user, $group) ? 'delegate' : $user->role));
            if ($kind === 'private_question' && ! empty($data['assessment_id']) && $user->isStudent()) {
                $eligibility = 'grade_owner';
            }
            abort_unless($this->eligible($user, $group, $eligibility, $kind === 'private_question' ? $offering : null, $data['assessment_id'] ?? null), 403);
            $roles[$user->id] = $eligibility;
        }
        $rolesPresent = array_values(array_unique($roles));
        $valid = match ($kind) {
            'private_question' => count($ids) === 2 && count($rolesPresent) === 2 && (in_array('student', $rolesPresent) || in_array('grade_owner', $rolesPresent)) && in_array('teacher', $rolesPresent),
            'delegate_contact' => count($ids) === 2 && in_array('delegate', $rolesPresent) && in_array('student', $rolesPresent),
            'representation' => in_array('delegate', $rolesPresent) && in_array('teacher', $rolesPresent) && array_diff($rolesPresent, ['delegate', 'teacher']) === [],
            'organization' => in_array('delegate', $rolesPresent) && in_array('admin', $rolesPresent) && array_diff($rolesPresent, ['delegate', 'admin']) === [],
            default => false,
        };
        abort_unless($valid, 422);
        if (! empty($data['assessment_id'])) {
            $a = DB::table('official_assessments')->find($data['assessment_id']);
            abort_unless($kind === 'private_question' && $a && $a->published_revision_id && DB::table('module_offerings')->where('id', $a->offering_id)->where('classroom_id', $group->id)->exists(), 422);
            foreach ($users as $user) {
                abort_unless($user->isTeacher() ? $this->access->teaches($user, $a->offering_id) : DB::table('assessment_candidates')->where('assessment_id', $a->id)->where('student_id', $user->id)->exists(), 403);
            }
        }

        return DB::transaction(function () use ($actor, $data, $roles, $offering) {
            $id = DB::table('school_threads')->insertGetId(['classroom_id' => $data['classroom_id'], 'offering_id' => $data['kind'] === 'private_question' ? $offering : null, 'assessment_id' => $data['assessment_id'] ?? null,
                'created_by' => $actor->id, 'kind' => $data['kind'], 'subject' => $data['subject'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($roles as $userId => $eligibility) {
                DB::table('school_thread_participants')->insert(['thread_id' => $id, 'user_id' => $userId, 'eligibility' => $eligibility]);
            }
            $this->message($actor, $id, $data['body']);
            AuditLog::record($actor, 'school.thread.create', ['thread_id' => $id, 'kind' => $data['kind']]);

            return $id;
        });
    }

    public function message(User $actor, int $threadId, string $body): int
    {
        return DB::transaction(function () use ($actor, $threadId, $body) {
            DB::table('school_threads')->where('id', $threadId)->lockForUpdate()->first();
            $this->thread($actor, $threadId, true);
            $id = DB::table('school_messages')->insertGetId(['thread_id' => $threadId, 'author_id' => $actor->id, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_threads')->where('id', $threadId)->update(['updated_at' => now()]);
            foreach (DB::table('school_thread_participants')->where('thread_id', $threadId)->where('user_id', '!=', $actor->id)->get() as $participant) {
                DB::table('school_notification_outbox')->insertOrIgnore(['event_key' => 'message:'.$id, 'user_id' => $participant->user_id, 'type' => 'school_message_received',
                    'payload' => json_encode(['thread_id' => $threadId, 'url' => '/app/school/messages/'.$threadId]), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->grades->queueNotifications();

            return $id;
        });
    }
}
