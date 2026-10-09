<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** File-fed local import. No real roster or credential is embedded in source. */
final class LocalSchoolAccountImport
{
    public function run(array $input, User $admin): array
    {
        if (! app()->environment(['local', 'testing']) || DB::getDefaultConnection() !== 'sqlite' || config('database.connections.sqlite.url') || ! $admin->isAdmin() || ! $admin->is_active) {
            throw new RuntimeException('An existing active admin and an explicitly local SQLite DB are required.');
        }
        $rows = $this->validate($input);
        $originalNotify = app(NotificationService::class);
        app()->instance(NotificationService::class, new class extends NotificationService
        {
            public function notify(User $user, string $type, array $payload = []): ?AppNotification
            {
                return null;
            }
        });
        try {
            return DB::transaction(function () use ($input, $rows, $admin) {
                $adminBefore = User::where('role', 'admin')->get()->mapWithKeys(fn ($u) => [$u->id => $u->getRawOriginal()])->all();
                $notificationsBefore = DB::table('notifications')->count();
                $delegatesBefore = DB::table('class_delegates')->count();
                $originalIds = User::pluck('id')->all();
                $stats = ['created_accounts' => 0, 'updated_accounts' => 0, 'unchanged_accounts' => 0, 'removed_accounts' => 0,
                    'preserved_non_target_accounts' => 0, 'preserved_demo_accounts' => [], 'enrollments_created' => 0, 'assignments_created' => 0,
                    'modules_created' => 0, 'offerings_created' => 0, 'matched_ids_preserved' => [], 'account_ids' => []];
                // Freeze evidence before replacing a matched demo account's name/email case.
                $demo = $this->demoEvidence();
                $year = DB::table('academic_years')->where('name', $input['academic_year'])->first();
                if (! $year) {
                    $yearId = DB::table('academic_years')->insertGetId(['name' => $input['academic_year'], 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    if ($year->status !== 'active') {
                        throw new RuntimeException('Existing academic year is not active; preserved without modification.');
                    }
                    $yearId = $year->id;
                }
                $codes = array_unique([$input['group_code'], ...($input['group_aliases'] ?? [])]);
                $groups = Classroom::where(function ($q) use ($codes) {
                    $q->whereIn('official_code', $codes)->orWhereIn('name', $codes)->orWhereIn('group_label', $codes);
                })->where(function ($q) use ($input, $yearId) {
                    $q->where('academic_year_id', $yearId)->orWhere('school_year', $input['academic_year']);
                })->lockForUpdate()->get();
                if ($groups->count() > 1) {
                    throw new RuntimeException('Multiple candidate groups exist; no automatic merge or duplicate creation is allowed.');
                }
                $setup = app(SchoolSetupService::class);
                $group = $groups->first();
                if ($group) {
                    if ($group->status !== 'active') {
                        throw new RuntimeException('Existing group is archived; preserved without automatic restoration.');
                    }
                    if ($group->teacher_id !== null) {
                        AuditLog::record($admin, 'school.local.group.converted', ['classroom_id' => $group->id, 'previous_teacher_id' => $group->teacher_id]);
                    }
                    // Official groups use teaching assignments rather than a legacy owner.
                    $group->forceFill(['name' => $input['group_code'], 'official_code' => $input['group_code'], 'group_label' => $input['group_code'], 'academic_year_id' => $yearId, 'is_official' => true, 'teacher_id' => null])->save();
                } else {
                    $group = $setup->createGroup($admin, ['name' => $input['group_code'], 'official_code' => $input['group_code'], 'academic_year_id' => $yearId, 'filiere' => $input['filiere'] ?? 'Non précisée', 'level' => $input['level'] ?? 'Non précisé']);
                }
                foreach ($rows as $row) {
                    $matches = User::whereRaw('LOWER(email) = ?', [strtolower($row['email'])])->lockForUpdate()->get();
                    if ($matches->count() > 1) {
                        throw new RuntimeException('Ambiguous email identity; import rolled back.');
                    }
                    $user = $matches->first() ?? new User;
                    $exists = $user->exists;
                    if ($exists && $user->isAdmin()) {
                        throw new RuntimeException('A listed account is an administrator. It cannot be downgraded or duplicated.');
                    }
                    if ($exists && ! in_array($user->role, [$row['role'], 'pending'], true)) {
                        throw new RuntimeException('Existing role conflicts with supplied account; import rolled back.');
                    }
                    $beforeId = $user->id;
                    $user->forceFill(['display_name' => $row['name'], 'email' => $row['email'], 'role' => $row['role'], 'role_locked' => true, 'is_active' => true]);
                    if (! $exists) {
                        $user->locale = 'fr';
                    }
                    if ($row['role'] === 'student' && ! $user->school_identifier) {
                        $user->school_identifier = explode('@', $row['email'])[0];
                    }
                    $changed = $user->isDirty();
                    if (! $exists) {
                        $stats['created_accounts']++;
                    } elseif ($changed) {
                        $stats['updated_accounts']++;
                    } else {
                        $stats['unchanged_accounts']++;
                    }
                    if ($changed || ! $exists) {
                        $user->save();
                    }
                    if ($exists) {
                        $stats['matched_ids_preserved'][] = $beforeId;
                    }
                    $stats['account_ids'][] = $user->id;
                    if ($row['role'] === 'student') {
                        $active = DB::table('school_enrollments')->where('academic_year_id', $yearId)->where('active_student_id', $user->id)->first();
                        if ($active && $active->classroom_id !== $group->id) {
                            throw new RuntimeException('A listed student has another primary group; no unrelated enrollment was changed.');
                        }
                        $membership = Membership::where('classroom_id', $group->id)->where('student_id', $user->id)->first();
                        $priorAdmission = $membership?->only(['requested_at', 'decided_at', 'decided_by']);
                        $wasAccepted = $membership?->status === 'accepted';
                        if (! $active) {
                            $setup->enroll($admin, $group, $user);
                            $stats['enrollments_created']++;
                        }
                        if ($wasAccepted) {
                            Membership::whereKey($membership->id)->update($priorAdmission);
                        }
                    } else {
                        $moduleMatches = DB::table('school_modules')->whereRaw('LOWER(code) = ?', [strtolower($row['module_code'])])->get();
                        if ($moduleMatches->count() > 1) {
                            throw new RuntimeException('Ambiguous matching module code.');
                        }
                        $module = $moduleMatches->first();
                        if (! $module) {
                            $moduleId = DB::table('school_modules')->insertGetId(['code' => $row['module_code'], 'name' => $row['module_title'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                            $stats['modules_created']++;
                        } else {
                            if ($module->status !== 'active') {
                                throw new RuntimeException('Matching module is archived; no unrelated catalogue state was changed.');
                            }
                            $moduleId = $module->id;
                            if ($module->name !== $row['module_title'] || $module->code !== $row['module_code']) {
                                DB::table('school_modules')->where('id', $moduleId)->update(['code' => $row['module_code'], 'name' => $row['module_title'], 'updated_at' => now()]);
                            }
                        }
                        $offering = DB::table('module_offerings')->where('classroom_id', $group->id)->where('module_id', $moduleId)->first();
                        if (! $offering) {
                            $offeringId = DB::table('module_offerings')->insertGetId(['classroom_id' => $group->id, 'module_id' => $moduleId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                            $stats['offerings_created']++;
                        } else {
                            if ($offering->status !== 'active') {
                                throw new RuntimeException('Matching offering is archived; no automatic restoration performed.');
                            }
                            $offeringId = $offering->id;
                        }
                        if (! DB::table('teaching_assignments')->where('offering_id', $offeringId)->where('active_teacher_id', $user->id)->exists()) {
                            $setup->assign($admin, $offeringId, $user);
                            $stats['assignments_created']++;
                        }
                    }
                }
                foreach ($demo['users'] as $fake) {
                    if (in_array($fake->id, $stats['account_ids'], true) || $fake->isAdmin()) {
                        continue;
                    }
                    $reason = $this->unsafeRelatedRecords($fake, $demo['class_ids']);
                    if ($reason) {
                        $stats['preserved_demo_accounts'][] = ['id' => $fake->id, 'reason' => $reason];

                        continue;
                    }
                    $fake->tokens()->delete();
                    $fake->delete();
                    $stats['removed_accounts']++;
                }
                $stats['preserved_non_target_accounts'] = User::whereIn('id', array_diff($originalIds, $stats['account_ids']))->count();
                foreach ($adminBefore as $id => $attributes) {
                    $after = User::findOrFail($id)->getRawOriginal();
                    foreach ($attributes as $key => $value) {
                        if (($after[$key] ?? null) !== $value) {
                            throw new RuntimeException('Administrator preservation check failed; rolled back.');
                        }
                    }
                }
                if (DB::table('notifications')->count() !== $notificationsBefore || DB::table('class_delegates')->count() !== $delegatesBefore) {
                    throw new RuntimeException('Import must not send notifications or appoint delegates.');
                }
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Foreign-key integrity check failed.');
                }
                $stats += ['group_code' => $group->official_code, 'group_id' => $group->id, 'academic_year' => $input['academic_year'],
                    'active_student_enrollments' => DB::table('school_enrollments')->where('classroom_id', $group->id)->whereNotNull('active_student_id')->whereNull('ends_at')->count(),
                    'active_teaching_assignments' => DB::table('teaching_assignments')->whereIn('offering_id', DB::table('module_offerings')->where('classroom_id', $group->id)->select('id'))->whereNotNull('active_teacher_id')->whereNull('ends_at')->count(),
                    'total_accounts' => User::count()];
                AuditLog::record($admin, 'school.local.import', ['classroom_id' => $group->id, 'created' => $stats['created_accounts'], 'updated' => $stats['updated_accounts'], 'removed' => $stats['removed_accounts']]);

                return $stats;
            });
        } finally {
            app()->instance(NotificationService::class, $originalNotify);
        }
    }

    private function validate(array $input): array
    {
        validator($input, ['group_code' => 'required|string|max:64', 'group_aliases' => 'sometimes|array', 'group_aliases.*' => 'string|max:64', 'academic_year' => 'required|string|max:32', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on',
            'students' => 'required|array|min:1', 'students.*.name' => 'required|string|max:255', 'students.*.email' => 'required|email:rfc',
            'teachers' => 'required|array|min:1', 'teachers.*.name' => 'required|string|max:255', 'teachers.*.email' => 'required|email:rfc',
            'teachers.*.module_code' => 'required|string|max:64', 'teachers.*.module_title' => 'required|string|max:255'])->validate();
        $rows = [...array_map(fn ($r) => [...$r, 'role' => 'student'], $input['students']), ...array_map(fn ($r) => [...$r, 'role' => 'teacher'], $input['teachers'])];
        $seen = [];
        foreach ($rows as $row) {
            $key = strtolower($row['email']);
            if (! str_ends_with($key, '@ofppt-edu.ma') || isset($seen[$key]) || ! in_array($row['role'], ['student', 'teacher'], true)) {
                throw new RuntimeException('Duplicate, conflicting or non-school input identity.');
            }
            $seen[$key] = true;
        }

        return $rows;
    }

    /** Strict signatures derived from database/seeders/DemoSeeder.php, never a broad name deletion. */
    private function demoEvidence(): array
    {
        $first = ['Yassine', 'Salma', 'Amine', 'Imane', 'Oussama', 'Nour', 'Karim', 'Hiba', 'Reda', 'Meryem', 'Anas', 'Sanaa', 'Younes', 'Aya', 'Bilal', 'Ghita', 'Mehdi', 'Lina', 'Tarek', 'Rim', 'Adam', 'Sara', 'Nabil', 'Ines'];
        $last = ['Alaoui', 'Benali', 'Chraibi', 'El Fassi', 'Fantini', 'Guerbaoui', 'Haddad', 'Idrissi', 'Jabri', 'Kettani', 'Lamrani', 'Mansouri', 'Naciri', 'Ouazzani', 'Ouazzani', 'Sabri', 'Tahiri', 'Zniber', 'Amrani', 'Belkadi', 'Cherkaoui', 'Daoudi', 'El Ghazi', 'Farid'];
        $users = [];
        foreach ($first as $i => $name) {
            $user = User::where('email', '20070314000'.(94 + $i).'@ofppt-edu.ma')->first();
            if ($user && $user->display_name === $name.' '.$last[$i] && $user->isStudent() && ! $user->role_locked && ! $user->school_identifier && ! $user->microsoft_object_id && ! $user->microsoft_verified_at) {
                $users[] = $user;
            }
        }
        $teacher = User::where('email', 'hamza.bouzid@ofppt-edu.ma')->first();
        if ($teacher && $teacher->display_name === 'Hamza Bouzid' && $teacher->isTeacher() && ! $teacher->microsoft_object_id && ! $teacher->microsoft_verified_at) {
            $users[] = $teacher;
        }
        $classIds = [];
        $definitions = [
            ['TDI2025A', 'Développement Web — TDI 1', 'Développement Web', 'TDI 1', '2025-2026'],
            ['BDD2025B', 'Bases de données — TDI 2', 'Bases de données', 'TDI 2', '2025-2026'],
            ['RES2025C', 'Réseaux informatiques — TDI 3', 'Réseaux', 'TDI 3', '2025-2026'],
            ['ALG2024D', 'Algorithmique — TDI 1 (année précédente)', 'Algorithmique', 'TDI 1', '2024-2025'],
        ];
        foreach ($definitions as [$code, $name, $subject, $label, $year]) {
            $class = Classroom::where('join_code', $code)->where('name', $name)->where('subject', $subject)->where('group_label', $label)->where('school_year', $year)->where('is_official', false)->first();
            if ($class) {
                $classIds[] = $class->id;
            }
        }

        return ['users' => $users, 'class_ids' => $classIds];
    }

    private function unsafeRelatedRecords(User $user, array $demoClasses): ?string
    {
        if ($user->isTeacher()) {
            return 'Confirmed demo teacher retained because historical teaching/ownership records are preserved.';
        }
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables as $table) {
            $name = $table->name;
            foreach (DB::select('PRAGMA foreign_key_list("'.str_replace('"', '""', $name).'")') as $fk) {
                if ($fk->table !== 'users') {
                    continue;
                }
                $references = DB::table($name)->where($fk->from, $user->id)->get();
                if ($references->isEmpty()) {
                    continue;
                }
                if ($name === 'audit_logs' && strtoupper($fk->on_delete) === 'SET NULL') {
                    continue;
                } // Keep audit rows, not delete them.
                if ($name === 'memberships' && $references->every(fn ($r) => in_array($r->classroom_id, $demoClasses, true))) {
                    continue;
                }
                if ($name === 'attempts' && $references->every(fn ($r) => $r->attempt_no === 1 && in_array(DB::table('quizzes')->where('id', $r->quiz_id)->value('classroom_id'), $demoClasses, true))) {
                    continue;
                }
                if ($name === 'partner_profiles') {
                    continue;
                } // Entire profile is owned by this confirmed fake identity.
                if ($name === 'partner_requests' && $references->every(fn ($r) => $r->status === 'pending' && in_array($r->classroom_id, $demoClasses, true))) {
                    continue;
                }

                return 'Retained: related records outside the confirmed demo-owned replacement scope ('.$name.').';
            }
        }

        return null;
    }
}
