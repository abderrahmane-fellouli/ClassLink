<?php

// Synthetic, disposable browser-audit data. Never a production seeder.
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TokenService;

require __DIR__.'/create-school-pilot-fixtures.php'; // Includes the strict isolated SQLite guard.
$actors = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
for ($i = 3; $i <= 10; $i++) {
    $user = new User;
    $user->forceFill(['email' => 'synthetic.ux.teacher'.$i.'@ofppt-edu.ma', 'display_name' => 'Synthetic teacher'.$i,
        'role' => 'teacher', 'role_locked' => true, 'is_active' => true, 'locale' => 'en'])->save();
}
foreach (['empty' => 'student', 'pending' => 'pending', 'suspended' => 'student'] as $label => $role) {
    $user = new User;
    $user->forceFill(['email' => 'synthetic.ux.'.$label.'@ofppt-edu.ma', 'display_name' => 'Synthetic '.$label,
        'role' => $role, 'role_locked' => true, 'is_active' => $label !== 'suspended', 'locale' => 'en'])->save();
    $actors[$label] = ['id' => $user->id, 'token' => app(TokenService::class)->issue($user, 'synthetic-ux-audit')];
}
$legacy = Classroom::create(['teacher_id' => $actors['teacher1']['id'], 'name' => 'Synthetic historical group',
    'subject' => 'Synthetic practice', 'group_label' => 'SYN-OLD', 'school_year' => '2026-2027',
    'join_code' => 'SYNOLD01', 'join_enabled' => true, 'status' => 'active']);
foreach (['student1', 'student2'] as $label) {
    Membership::create(['classroom_id' => $legacy->id, 'student_id' => $actors[$label]['id'], 'status' => 'accepted', 'requested_at' => now(), 'decided_at' => now()]);
}
$actors['legacy_id'] = $legacy->id;
foreach (['admin', 'teacher1', 'student1', 'student2'] as $label) {
    foreach (NotificationService::types() as $type) {
        app(NotificationService::class)->notify(User::findOrFail($actors[$label]['id']), $type, [
            'title' => 'Synthetic update', 'classroom_name' => 'Synthetic group', 'module_name' => 'Synthetic module',
            'student_name' => 'Synthetic student', 'teacher_name' => 'Synthetic teacher', 'from_name' => 'Synthetic peer',
            'status' => $type === NotificationService::AI_JOB_FINISHED ? 'done' : 'accepted', 'action' => 'assigned', 'url' => '/app/school',
        ]);
    }
}
file_put_contents($argv[1], json_encode($actors, JSON_THROW_ON_ERROR));
echo "Synthetic UX actors and historical group prepared. No email sent.\n";
