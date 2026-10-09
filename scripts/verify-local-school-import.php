<?php

// Read-only checks against private input and the pre-upgrade SQLite snapshot.
if (PHP_SAPI !== 'cli' || $argc !== 6) {
    throw new RuntimeException('Usage: php verify-local-school-import.php db.sqlite roster.json before.sqlite first-report.json rerun-report.json');
}
function openLocal(string $file): PDO
{
    $path = realpath($file);
    if (! $path || str_starts_with($path, '\\\\') || str_starts_with($path, '//') || file_get_contents($path, false, null, 0, 16) !== "SQLite format 3\0") {
        throw new RuntimeException('Existing local SQLite required.');
    }
    $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA query_only=ON');

    return $pdo;
}
function rows(PDO $db, string $table): array
{
    return $db->query('SELECT * FROM "'.$table.'" ORDER BY id')->fetchAll();
}
function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message.PHP_EOL;
}
$db = openLocal($argv[1]);
$before = openLocal($argv[3]);
$input = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$first = json_decode(file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR);
$rerun = json_decode(file_get_contents($argv[5]), true, flags: JSON_THROW_ON_ERROR);
$users = rows($db, 'users');
$byId = array_column($users, null, 'id');
$byEmail = array_column($users, null, 'email');
$oldUsers = rows($before, 'users');
$oldByEmail = [];
foreach ($oldUsers as $u) {
    $oldByEmail[strtolower($u['email'])] = $u;
}
$targetIds = [];
foreach (['students' => 'student', 'teachers' => 'teacher'] as $list => $role) {
    foreach ($input[$list] as $row) {
        $user = $byEmail[$row['email']] ?? null;
        check($user && $user['display_name'] === $row['name'] && $user['role'] === $role && $user['role_locked'] === 1 && $user['is_active'] === 1, 'Supplied identity spelling and locked role: '.$list.' item '.(count($targetIds) + 1));
        $targetIds[] = $user['id'];
        if ($old = $oldByEmail[strtolower($row['email'])] ?? null) {
            check($old['id'] === $user['id'], 'Matched account ID preserved');
            foreach (['microsoft_tenant_id', 'microsoft_object_id', 'microsoft_verified_at'] as $field) {
                check(($old[$field] ?? null) === ($user[$field] ?? null), 'Matched Microsoft field preserved: '.$field);
            }
        }
    }
}
foreach ($oldUsers as $old) {
    if ($old['role'] === 'admin' || (isset($byId[$old['id']]) && ! in_array($old['id'], $targetIds, true))) {
        $after = array_intersect_key($byId[$old['id']], $old);
        check($after == $old, 'Administrator/non-target account row preserved');
    }
}
$groups = rows($db, 'classrooms');
$groupById = array_column($groups, null, 'id');
foreach (rows($before, 'classrooms') as $old) {
    if ($old['id'] !== $first['group_id']) {
        check(array_intersect_key($groupById[$old['id']], $old) == $old, 'Unrelated historical classroom preserved');
    }
}
check($groupById[$first['group_id']]['official_code'] === $input['group_code'], 'Canonical group reused');
foreach (['quizzes', 'questions', 'options', 'assignments', 'announcements', 'flashcard_decks', 'flashcards', 'notifications'] as $table) {
    $oldRows = rows($before, $table);
    $newById = array_column(rows($db, $table), null, 'id');
    foreach ($oldRows as $old) {
        check(isset($newById[$old['id']]) && array_intersect_key($newById[$old['id']], $old) == $old, 'Historical record preserved: '.$table.' #'.$old['id']);
    }
}
$memberships = array_column(rows($db, 'memberships'), null, 'id');
foreach (rows($before, 'memberships') as $old) {
    if (in_array($old['student_id'], $targetIds, true)) {
        unset($old['updated_at']);
        check(isset($memberships[$old['id']]) && array_intersect_key($memberships[$old['id']], $old) == $old, 'Matched enrollment membership and decision history preserved');
    }
}
foreach (['attempts', 'attempt_answers', 'partner_profiles'] as $table) {
    $oldRows = rows($before, $table);
    $after = array_column(rows($db, $table), null, 'id');
    $attempts = array_column(rows($before, 'attempts'), null, 'id');
    foreach ($oldRows as $old) {
        $owner = $old['student_id'] ?? $old['user_id'] ?? ($attempts[$old['attempt_id']]['student_id'] ?? null);
        if (in_array($owner, $targetIds, true)) {
            check(isset($after[$old['id']]) && array_intersect_key($after[$old['id']], $old) == $old, 'Matched historical personal record preserved: '.$table);
        }
    }
}
check($db->query('PRAGMA foreign_key_check')->fetchAll() === [], 'Foreign keys valid');
check($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite integrity valid');
check($first['active_student_enrollments'] === count($input['students']) && $first['active_teaching_assignments'] === count($input['teachers']), 'Expected active enrollment and assignment counts');
check($rerun['account_ids'] === $first['account_ids'], 'Rerun account IDs stable');
foreach (['created_accounts', 'updated_accounts', 'removed_accounts', 'enrollments_created', 'assignments_created', 'modules_created', 'offerings_created'] as $key) {
    check($rerun[$key] === 0, 'Rerun no-op: '.$key);
}
check((int) $db->query('SELECT COUNT(*) FROM class_delegates')->fetchColumn() === 0, 'No delegates appointed');
echo 'All read-only import checks passed.'.PHP_EOL;
