<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SchoolNoticeService
{
    public function __construct(private SchoolAccess $access, private SchoolCommunicationService $messages) {}

    private function audiences(User $actor, array $spec): array
    {
        $this->access->assertActive($actor);
        if ($spec['audience'] === 'all') {
            $this->access->admin($actor);
            $ids = User::where('is_active', true)->whereIn('role', ['student', 'teacher', 'admin'])->orderBy('id')->pluck('id')->all();
            abort_if(count($ids) > 5000, 422);

            return [['classroom_id' => null, 'roles' => array_fill_keys($ids, 'account')]];
        }
        if ($spec['audience'] === 'delegates') {
            $this->access->admin($actor);
        }
        $audiences = [];
        foreach ($spec['group_ids'] as $groupId) {
            $group = $this->access->group($groupId);
            $this->access->writable($group);
            abort_unless($actor->isAdmin() || ($actor->isTeacher() && $this->access->canView($actor, $group))
                || ($group->delegate_notices_enabled && $this->access->delegate($actor, $group)), 403);
            $roles = [];
            if ($spec['audience'] === 'delegates') {
                $users = User::where('is_active', true)->where('role', 'student')->whereIn('id', DB::table('class_delegates')->where('classroom_id', $group->id)->whereNotNull('active_slot')->where('ends_at', '>', now())->whereNull('revoked_at')->select('student_id'))->get();
                foreach ($users as $user) {
                    if ($this->access->delegate($user, $group)) {
                        $roles[$user->id] = 'delegate';
                    }
                }
            } else {
                $users = User::where('is_active', true)->where('role', 'student')->whereIn('id', DB::table('school_enrollments')->where('classroom_id', $group->id)->whereNotNull('active_student_id')->select('student_id'))->get();
                foreach ($users as $user) {
                    $roles[$user->id] = 'student';
                }
                $staff = User::where('is_active', true)->where('role', 'teacher')->whereIn('id', DB::table('teaching_assignments')->whereNotNull('active_teacher_id')->whereNull('ends_at')->whereIn('offering_id', DB::table('module_offerings')->where('classroom_id', $group->id)->select('id'))->select('teacher_id'))->get();
                foreach ($staff as $user) {
                    $roles[$user->id] = 'teacher';
                }
            }
            $roles[$actor->id] = $actor->isAdmin() ? 'admin' : ($actor->isTeacher() ? 'teacher' : 'delegate');
            ksort($roles);
            abort_if(count($roles) > 5000, 422);
            $audiences[] = ['classroom_id' => $group->id, 'roles' => $roles];
        }

        return $audiences;
    }

    public function preview(User $actor, array $spec): array
    {
        $audiences = $this->audiences($actor, $spec);
        $id = (string) Str::uuid();
        Cache::put('school-notice:'.$actor->id.':'.$id, ['spec' => $spec, 'audiences' => $audiences], now()->addMinutes(10));
        $ids = array_unique(array_merge(...array_map(fn ($a) => array_keys($a['roles']), $audiences)));

        return ['preview_id' => $id, 'audience' => $spec['audience'], 'recipient_count' => count($ids),
            'breakdown' => array_map(fn (array $a) => ['classroom_id' => $a['classroom_id'], 'recipient_count' => count($a['roles'])], $audiences),
            'recipients_preview' => User::whereIn('id', $ids)->select('id', 'display_name')->orderBy('id')->limit(20)->get()];
    }

    public function publish(User $actor, string $previewId, string $subject, string $body): array
    {
        $key = 'school-notice:'.$actor->id.':'.$previewId;

        return Cache::lock($key.':lock', 30)->block(5, function () use ($actor, $key, $subject, $body) {
            $saved = Cache::get($key);
            abort_unless($saved, 410);
            $current = $this->audiences($actor, $saved['spec']);
            abort_unless($current === $saved['audiences'], 409, __('api.school.audience_changed'));
            if (isset($saved['thread_ids'])) {
                return $saved['thread_ids'];
            }
            $ids = DB::transaction(function () use ($actor, $key, $current, $subject, $body) {
                $ids = [];
                foreach ($current as $audience) {
                    $noticeKey = hash('sha256', $key.':'.($audience['classroom_id'] ?? 'all'));
                    if ($existing = DB::table('school_threads')->where('notice_key', $noticeKey)->first()) {
                        $ids[] = $existing->id;

                        continue;
                    }
                    $id = DB::table('school_threads')->insertGetId(['notice_key' => $noticeKey, 'classroom_id' => $audience['classroom_id'], 'created_by' => $actor->id,
                        'kind' => 'class_notice', 'subject' => $subject, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                    $participants = [];
                    foreach ($audience['roles'] as $userId => $eligibility) {
                        $participants[] = ['thread_id' => $id, 'user_id' => $userId, 'eligibility' => $eligibility];
                    }
                    foreach (array_chunk($participants, 500) as $chunk) {
                        DB::table('school_thread_participants')->insert($chunk);
                    }
                    $this->messages->message($actor, $id, $body);
                    $ids[] = $id;
                }
                AuditLog::record($actor, 'school.notice.publish', ['thread_ids' => $ids]);

                return $ids;
            });
            Cache::put($key, $saved + ['thread_ids' => $ids], now()->addMinutes(10));

            return $ids;
        });
    }
}
