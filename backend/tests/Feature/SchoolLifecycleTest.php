<?php

namespace Tests\Feature;

use App\Console\Commands\SchoolMappingReport;
use App\Models\AppNotification;
use App\Models\Classroom;
use App\Models\FlashcardDeck;
use App\Models\Membership;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\SchoolFixture;
use Tests\TestCase;

/**
 * Gap-closing pass: academic-year editing, module/offering lifecycle,
 * group restore, notification coverage for school setup events, and the
 * read-only legacy mapping report inventories.
 */
class SchoolLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SchoolFixture;

    private function assertNotified(User $user, string $type, ?string $action = null): AppNotification
    {
        $notification = AppNotification::where('user_id', $user->id)->where('type', $type)->orderByDesc('id')->first();
        $this->assertNotNull($notification, "Expected a '{$type}' notification for user {$user->id}");
        if ($action !== null) {
            $this->assertSame($action, $notification->payload['action'] ?? null);
        }

        return $notification;
    }

    // -- Academic year editing ------------------------------------------------

    public function test_admin_edits_an_active_year_but_a_closed_year_only_keeps_its_label(): void
    {
        [$admin, $group] = $this->schoolFixture();
        $year = (int) $group->academic_year_id;
        $api = $this->asToken($this->tokenFor($admin));

        $api->patchJson("/api/school/years/$year", ['name' => 'Renamed year', 'ends_on' => now()->addMonths(6)->toDateString()])->assertNoContent();
        $this->assertDatabaseHas('academic_years', ['id' => $year, 'name' => 'Renamed year']);

        // ends_on before starts_on is rejected against the stored values.
        $api->patchJson("/api/school/years/$year", ['ends_on' => now()->subMonths(2)->toDateString()])->assertStatus(422);

        // Unique name, ignoring the edited row.
        $duplicate = $api->postJson('/api/school/years', ['name' => 'Taken label', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString()])->assertCreated()->json('id');
        $api->patchJson("/api/school/years/$year", ['name' => 'Taken label'])->assertStatus(422);

        $api->postJson("/api/school/years/$year/archive")->assertNoContent();
        $api->patchJson("/api/school/years/$year", ['ends_on' => now()->addYear()->toDateString()])->assertStatus(422);
        $api->patchJson("/api/school/years/$year", ['name' => 'Closed label'])->assertNoContent();
        $this->assertDatabaseHas('academic_years', ['id' => $year, 'name' => 'Closed label']);
        $this->assertNotSame('Closed label', DB::table('academic_years')->where('id', $duplicate)->value('name'));
    }

    // -- Module / offering lifecycle ------------------------------------------

    public function test_admin_edits_modules_and_an_archived_definition_cannot_seed_a_new_offering(): void
    {
        [$admin, $group] = $this->schoolFixture();
        $api = $this->asToken($this->tokenFor($admin));
        $module = $api->postJson('/api/school/modules', ['code' => 'SYN-NEW', 'name' => 'New module'])->assertCreated()->json('id');

        $api->patchJson("/api/school/modules/$module", ['name' => 'Renamed module', 'code' => 'SYN-NEW-2'])->assertNoContent();
        $this->assertDatabaseHas('school_modules', ['id' => $module, 'name' => 'Renamed module', 'code' => 'SYN-NEW-2']);

        $taken = DB::table('school_modules')->orderBy('id')->value('code');
        $api->patchJson("/api/school/modules/$module", ['code' => $taken])->assertStatus(422);
        $api->patchJson("/api/school/modules/$module", ['status' => 'unknown'])->assertStatus(422);
        $api->patchJson('/api/school/modules/999999', ['name' => 'Ghost'])->assertNotFound();

        $api->patchJson("/api/school/modules/$module", ['status' => 'archived'])->assertNoContent();
        $api->postJson("/api/school/groups/{$group->id}/offerings", ['module_id' => $module])->assertStatus(422);

        $api->patchJson("/api/school/modules/$module", ['status' => 'active'])->assertNoContent();
        $api->postJson("/api/school/groups/{$group->id}/offerings", ['module_id' => $module])->assertCreated();
    }

    public function test_offering_status_is_admin_scoped_and_blocked_on_archived_groups(): void
    {
        [$admin, $group, $teachers, $offerings] = $this->schoolFixture();
        $this->asToken($this->tokenFor($teachers[0]))->patchJson("/api/school/offerings/{$offerings[0]}", ['status' => 'archived'])->assertForbidden();
        $this->asToken($this->tokenFor($admin))->patchJson('/api/school/offerings/999999', ['status' => 'archived'])->assertNotFound();

        $api = $this->asToken($this->tokenFor($admin));
        $api->patchJson("/api/school/offerings/{$offerings[0]}", ['status' => 'deleted'])->assertStatus(422);
        $api->patchJson("/api/school/offerings/{$offerings[0]}", ['status' => 'archived'])->assertNoContent();
        $this->assertDatabaseHas('module_offerings', ['id' => $offerings[0], 'status' => 'archived']);
        $api->patchJson("/api/school/offerings/{$offerings[0]}", ['status' => 'active'])->assertNoContent();
        $this->assertDatabaseHas('module_offerings', ['id' => $offerings[0], 'status' => 'active']);

        $api->postJson("/api/school/groups/{$group->id}/archive")->assertNoContent();
        $api->patchJson("/api/school/offerings/{$offerings[1]}", ['status' => 'archived'])->assertStatus(409);
    }

    // -- Group restore --------------------------------------------------------

    public function test_archived_group_is_restorable_unless_its_year_is_archived(): void
    {
        [$admin, $group] = $this->schoolFixture();
        $this->asToken($this->tokenFor($this->teacher()))->postJson("/api/school/groups/{$group->id}/restore")->assertForbidden();

        $api = $this->asToken($this->tokenFor($admin));
        $api->postJson("/api/school/groups/{$group->id}/restore")->assertStatus(409);

        $api->postJson("/api/school/groups/{$group->id}/archive")->assertNoContent();
        $this->assertDatabaseHas('classrooms', ['id' => $group->id, 'status' => 'archived']);
        $api->postJson("/api/school/groups/{$group->id}/restore")->assertNoContent();
        $this->assertDatabaseHas('classrooms', ['id' => $group->id, 'status' => 'active']);

        $api->postJson("/api/school/groups/{$group->id}/archive")->assertNoContent();
        $api->postJson("/api/school/years/{$group->academic_year_id}/archive")->assertNoContent();
        $api->postJson("/api/school/groups/{$group->id}/restore")->assertStatus(422);
    }

    // -- Notification coverage ------------------------------------------------

    public function test_school_setup_events_notify_the_people_concerned(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $api = $this->asToken($this->tokenFor($admin));

        // The fixture assigns both teachers: an assignment is announced.
        $this->assertNotified($teachers[0], NotificationService::TEACHING_ASSIGNMENT_CHANGED, 'assigned');

        $assignment = DB::table('teaching_assignments')->where('offering_id', $offerings[0])->value('id');
        $api->deleteJson("/api/school/teaching-assignments/$assignment")->assertNoContent();
        $this->assertNotified($teachers[0], NotificationService::TEACHING_ASSIGNMENT_CHANGED, 'revoked');

        // Delegates learn about appointment and revocation.
        $delegate = $api->postJson("/api/school/groups/{$group->id}/delegates", ['student_id' => $students[0]->id, 'ends_at' => now()->addMonth()->toIso8601String()])->assertCreated()->json('id');
        $this->assertNotified($students[0], NotificationService::DELEGATE_CHANGED, 'appointed');
        $api->deleteJson("/api/school/delegates/$delegate")->assertNoContent();
        $this->assertNotified($students[0], NotificationService::DELEGATE_CHANGED, 'revoked');

        // Roster additions and removals reach the student.
        $added = $this->student();
        $api->postJson("/api/school/groups/{$group->id}/enrollments", ['student_id' => $added->id])->assertNoContent();
        $this->assertNotified($added, NotificationService::MEMBERSHIP_ACCEPTED);
        $api->deleteJson("/api/school/groups/{$group->id}/enrollments/{$students[1]->id}")->assertNoContent();
        $this->assertNotified($students[1], NotificationService::MEMBERSHIP_REMOVED);

        // A rejected official decision tells the student too (the legacy join
        // flow already did; the school decision endpoint was silent).
        $pending = $this->student();
        $membership = Membership::create(['classroom_id' => $group->id, 'student_id' => $pending->id, 'status' => 'pending', 'requested_at' => now()]);
        $api->postJson("/api/school/memberships/{$membership->id}/decision", ['decision' => 'rejected'])->assertNoContent();
        $this->assertNotified($pending, NotificationService::MEMBERSHIP_REJECTED);

        // Assignment requests land on admins with a deep-linkable payload.
        $requester = $this->teacher(['role_locked' => true]);
        $this->asToken($this->tokenFor($requester))->postJson("/api/school/offerings/{$offerings[1]}/assignment-request", ['reason' => 'Please assign me'])->assertCreated();
        $notification = $this->assertNotified($admin, NotificationService::ASSIGNMENT_REQUEST_RECEIVED);
        $this->assertSame('/app/school', $notification->payload['url'] ?? null);
        $this->assertSame($requester->display_name, $notification->payload['teacher_name'] ?? null);

        $this->asToken($this->tokenFor($requester))->postJson('/api/school/setup-requests', [
            'requested_group_code' => 'LEG-1', 'requested_module_code' => 'LEG-M', 'requested_year' => '2025-2026', 'reason' => 'Legacy group',
        ])->assertCreated();
        $this->assertNotified($admin, NotificationService::ASSIGNMENT_REQUEST_RECEIVED);
    }

    public function test_notification_types_have_digest_labels_in_both_languages(): void
    {
        foreach (NotificationService::types() as $type) {
            foreach (['en', 'fr'] as $locale) {
                $translated = __('api.digest.types.'.$type, [], $locale);
                $this->assertNotSame("api.digest.types.$type", $translated, "Missing digest label for '{$type}' in '{$locale}'");
            }
        }
        $this->assertContains(NotificationService::TEACHING_ASSIGNMENT_CHANGED, NotificationService::types());
        $this->assertContains(NotificationService::ASSIGNMENT_REQUEST_RECEIVED, NotificationService::types());
        $this->assertContains(NotificationService::DELEGATE_CHANGED, NotificationService::types());
    }

    // -- Mapping report inventories (read-only) -------------------------------

    public function test_mapping_report_lists_content_inventories_and_writes_nothing(): void
    {
        [$admin, $group, $teachers, $offerings] = $this->schoolFixture();
        $legacy = Classroom::factory()->create(['teacher_id' => $this->teacher()->id, 'is_official' => false, 'name' => 'Legacy A', 'school_year' => '2025-2026']);
        Classroom::factory()->create(['teacher_id' => $legacy->teacher_id, 'is_official' => false, 'name' => 'Legacy A', 'school_year' => '2025-2026']);
        $legacyStudent = $this->student();
        Membership::create(['classroom_id' => $legacy->id, 'student_id' => $legacyStudent->id, 'status' => 'accepted', 'requested_at' => now()]);
        DB::table('announcements')->insert(['classroom_id' => $legacy->id, 'author_id' => $legacy->teacher_id, 'title' => 'Legacy notice', 'body' => 'Body', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assignments')->insert(['classroom_id' => $legacy->id, 'created_by' => $legacy->teacher_id, 'title' => 'Legacy work', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('quizzes')->insert(['classroom_id' => $legacy->id, 'created_by' => $legacy->teacher_id, 'title' => 'Legacy quiz', 'created_at' => now(), 'updated_at' => now()]);
        $deck = FlashcardDeck::create(['classroom_id' => $legacy->id, 'title' => 'Legacy deck', 'source' => 'manual', 'status' => 'published']);
        $deck->cards()->create(['front' => 'Q', 'back' => 'A', 'position' => 0]);

        $before = ['classrooms' => DB::table('classrooms')->count(), 'memberships' => DB::table('memberships')->count()];
        $mapFile = tempnam(sys_get_temp_dir(), 'clmap').'.json';
        file_put_contents($mapFile, json_encode([['legacy_classroom_id' => $legacy->id, 'offering_id' => $offerings[0]]]));
        try {
            $exit = Artisan::call(SchoolMappingReport::class, ['--map' => $mapFile]);
            $report = json_decode(Artisan::output(), true);
        } finally {
            @unlink($mapFile);
        }

        $this->assertSame(0, $exit);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(0, $report['writes']);
        $row = collect($report['rows'])->firstWhere('legacy_classroom_id', $legacy->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['accepted_members']);
        $this->assertSame(1, $row['inventory']['memberships']['accepted'] ?? 0);
        $this->assertSame(1, $row['inventory']['announcements']);
        $this->assertSame(1, $row['inventory']['assignments']);
        $this->assertSame(1, $row['inventory']['quizzes']);
        $this->assertSame(1, $row['inventory']['flashcard_decks']);
        $this->assertSame(1, $row['inventory']['flashcards']);
        $this->assertSame(0, $row['inventory']['materials']);
        $this->assertTrue($row['ambiguous_name_and_year']);
        $this->assertSame($group->id, $row['proposed_context']['group_id']);
        // The legacy classroom teacher differs from the offering's active teacher.
        $this->assertTrue($row['proposed_context']['teacher_mismatch']);
        $this->assertSame($legacy->teacher_id, $row['proposed_context']['legacy_teacher_id']);
        $this->assertArrayHasKey('review_required', $row);

        $this->assertSame($before['classrooms'], DB::table('classrooms')->count());
        $this->assertSame($before['memberships'], DB::table('memberships')->count());
        $this->assertDatabaseCount('module_offerings', count($offerings));
    }
}
