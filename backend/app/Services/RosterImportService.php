<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RosterImportService
{
    public function __construct(private SchoolAccess $access, private SchoolSetupService $setup) {}

    public function csv(string $path): array
    {
        $text = file_get_contents($path);
        abort_if(strlen($text) > 2097152, 422);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $headerLine = strtok($text, "\r\n");
        $delimiters = array_filter([';', ',', "\t"], fn ($d) => count(str_getcsv($headerLine ?: '', $d, '"', '')) === 3);
        abort_unless(count($delimiters) === 1, 422, __('api.school.invalid_import'));
        $f = fopen('php://temp', 'w+');
        fwrite($f, $text);
        rewind($f);
        $headers = fgetcsv($f, null, reset($delimiters), '"', '');
        abort_unless($headers && count(array_unique($headers)) === 3 && array_diff(['student_identifier', 'email', 'display_name'], $headers) === [], 422, __('api.school.invalid_import'));
        $rows = [];
        while (($row = fgetcsv($f, null, reset($delimiters), '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            abort_unless(count($row) === 3 && count($rows) < 2000, 422, __('api.school.invalid_import'));
            $rows[] = array_combine($headers, $row);
        }
        fclose($f);
        abort_if($rows === [], 422);

        return $rows;
    }

    private function validatedRows(Classroom $group, array $rows): array
    {
        $clean = $errors = $identifiers = $emails = [];
        // Prefetch groupé : 3 requêtes pour tout le lot (2000 lignes au maximum)
        // au lieu de 3 requêtes par ligne (6000 requêtes auparavant).
        $allEmails = $allIdentifiers = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $identifier = trim((string) ($row['student_identifier'] ?? ''));
            if ($email !== '') {
                $allEmails[$email] = $email;
            }
            if ($identifier !== '') {
                $allIdentifiers[$identifier] = $identifier;
            }
        }
        $byEmails = $allEmails === [] ? collect() : User::whereIn('email', array_values($allEmails))->get()->keyBy('email');
        $byIdentifiers = $allIdentifiers === [] ? collect() : User::whereIn('school_identifier', array_values($allIdentifiers))->get()->keyBy('school_identifier');
        $transferIds = DB::table('school_enrollments')
            ->where('academic_year_id', $group->academic_year_id)
            ->where('classroom_id', '!=', $group->id)
            ->whereIn('active_student_id', $byEmails->pluck('id')->all())
            ->pluck('active_student_id')->flip();
        foreach ($rows as $index => $row) {
            $identifier = trim((string) ($row['student_identifier'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $name = trim((string) ($row['display_name'] ?? ''));
            $byEmail = $byEmails->get($email);
            $byIdentifier = $byIdentifiers->get($identifier);
            $reason = null;
            if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $identifier) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_ends_with($email, '@ofppt-edu.ma') || $name === '' || mb_strlen($name) > 255) {
                $reason = 'invalid_roster_identity';
            } elseif (isset($identifiers[$identifier]) || isset($emails[$email])) {
                $reason = 'duplicate_roster_identity';
            } elseif ($byIdentifier && $byIdentifier->email !== $email) {
                $reason = 'identifier_email_conflict';
            } elseif ($byEmail && (($byEmail->school_identifier && $byEmail->school_identifier !== $identifier) || ! $byEmail->is_active || ! in_array($byEmail->role, ['student', 'pending']))) {
                $reason = 'existing_account_conflict';
            } elseif ($byEmail && $transferIds->has($byEmail->id)) {
                $reason = 'transfer_required';
            }
            $identifiers[$identifier] = $emails[$email] = true;
            if ($reason) {
                $errors[] = ['row' => $index + 2, 'reason' => $reason];
            } else {
                $clean[] = ['student_identifier' => $identifier, 'email' => $email, 'display_name' => $name, 'existing_user_id' => $byEmail?->id];
            }
        }

        return [$clean, $errors];
    }

    public function preview(User $actor, Classroom $group, array $rows): array
    {
        abort_unless($this->access->rosterManager($actor, $group), 403);
        $this->access->writable($group);
        [$clean, $errors] = $this->validatedRows($group, $rows);
        $id = (string) Str::uuid();
        DB::table('roster_import_batches')->insert(['id' => $id, 'classroom_id' => $group->id, 'actor_id' => $actor->id, 'roster_version' => $group->roster_version,
            'rows' => json_encode($clean), 'errors' => json_encode($errors), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return ['batch_id' => $id, 'rows' => $clean, 'errors' => $errors, 'new_accounts' => count(array_filter($clean, fn ($r) => ! $r['existing_user_id'])), 'no_messages_will_be_sent' => true];
    }

    public function commit(User $actor, Classroom $group, string $id): int
    {
        abort_unless($this->access->rosterManager($actor, $group), 403);
        $this->access->writable($group);

        return DB::transaction(function () use ($actor, $group, $id) {
            $group = Classroom::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $batch = DB::table('roster_import_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($batch && $batch->classroom_id === $group->id && $batch->actor_id === $actor->id, 404);
            if ($batch->committed_at) {
                return count(json_decode($batch->rows, true));
            }
            abort_unless($batch->roster_version === $group->roster_version, 409);
            abort_if(now()->parse($batch->expires_at)->isPast(), 410);
            abort_if(json_decode($batch->errors, true) !== [], 422);
            $rows = json_decode($batch->rows, true);
            [$clean, $errors] = $this->validatedRows($group, $rows);
            abort_if($errors !== [], 409);
            $users = $clean === [] ? collect() : User::whereIn('email', array_column($clean, 'email'))->get()->keyBy('email');
            foreach ($clean as $row) {
                $user = $users->get($row['email']) ?? new User(['email' => $row['email']]);
                $user->forceFill(['school_identifier' => $row['student_identifier'], 'display_name' => $row['display_name'], 'role' => 'student', 'role_locked' => true, 'is_active' => true, 'locale' => $user->locale ?: 'fr'])->save();
                $this->setup->enroll($actor, $group, $user);
            }
            DB::table('roster_import_batches')->where('id', $id)->update(['committed_at' => now(), 'updated_at' => now()]);
            AuditLog::record($actor, 'school.roster.import', ['classroom_id' => $group->id, 'batch_id' => $id, 'count' => count($clean)]);

            return count($clean);
        });
    }
}
