<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use App\Services\SchoolSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SchoolFixture;
use Tests\TestCase;

class SchoolTeachingTest extends TestCase
{
    use RefreshDatabase, SchoolFixture;

    public function test_module_resources_are_scoped_and_assignment_removal_revokes_author_access(): void
    {
        [$admin, $group, $teachers, $offerings, $students] = $this->schoolFixture();
        $path = "/api/school/offerings/{$offerings[0]}/tools/materials";
        $material = $this->asToken($this->tokenFor($teachers[0]))->postJson($path, ['title' => 'Synthetic module resource', 'type' => 'link', 'url' => 'https://example.test/resource'])->assertCreated()->json('id');
        $this->assertDatabaseHas('materials', ['id' => $material, 'offering_id' => $offerings[0], 'classroom_id' => $group->id]);
        $this->asToken($this->tokenFor($teachers[1]))->getJson($path)->assertForbidden();
        $this->postJson($path, ['title' => 'Forbidden', 'type' => 'link', 'url' => 'https://example.test'])->assertForbidden();
        $this->asToken($this->tokenFor($students[0]))->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/school/offerings/{$offerings[1]}/tools/materials")->assertOk()->assertJsonCount(0, 'data');
        $assignment = DB::table('teaching_assignments')->where('offering_id', $offerings[0])->first();
        app(SchoolSetupService::class)->revokeAssignment($admin, $assignment->id);
        $this->asToken($this->tokenFor($teachers[0]))->getJson($path)->assertForbidden();
    }

    public function test_official_assignments_have_draft_publication_and_closure_distinct_from_submissions(): void
    {
        [, , $teachers, $offerings, $students] = $this->schoolFixture();
        $path = "/api/school/offerings/{$offerings[0]}/tools/assignments";
        $id = $this->asToken($this->tokenFor($teachers[0]))->postJson($path, ['title' => 'Synthetic assignment', 'instructions' => 'Synthetic work', 'due_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('id');
        $this->assertDatabaseHas('assignments', ['id' => $id, 'publication_status' => 'draft']);
        $this->asToken($this->tokenFor($students[0]))->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("$path/$id")->assertNotFound();
        $this->asToken($this->tokenFor($teachers[0]))->postJson("$path/$id/publish")->assertOk();
        $this->asToken($this->tokenFor($students[0]))->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->asToken($this->tokenFor($teachers[0]))->postJson("$path/$id/close")->assertOk();
        $this->asToken($this->tokenFor($students[0]))->postJson("$path/$id/submit", ['file' => $this->fakeUpload('synthetic.pdf', 'application/pdf', 1)])->assertConflict();
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_deadline_change_is_silent_while_draft_and_notifies_students_once_published(): void
    {
        [, , $teachers, $offerings, $students] = $this->schoolFixture();
        $path = "/api/school/offerings/{$offerings[0]}/tools/assignments";
        $id = $this->asToken($this->tokenFor($teachers[0]))->postJson($path, ['title' => 'Synthetic assignment', 'due_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('id');

        // Un devoir officiel non publié (brouillon) ne notifie jamais un changement de date.
        $this->asToken($this->tokenFor($teachers[0]))->patchJson("$path/$id", ['due_at' => now()->addDays(2)->toIso8601String()])->assertOk();
        $this->assertSame(0, DB::table('notifications')->where('type', NotificationService::DEADLINE_CHANGED)->count());

        // Une fois publié, le changement de date notifie les stagiaires inscrits.
        $this->asToken($this->tokenFor($teachers[0]))->postJson("$path/$id/publish")->assertOk();
        $newDue = now()->addDays(3)->toIso8601String();
        $this->asToken($this->tokenFor($teachers[0]))->patchJson("$path/$id", ['due_at' => $newDue])->assertOk();
        $this->assertSame(2, DB::table('notifications')->where('type', NotificationService::DEADLINE_CHANGED)->count());
        foreach ($students as $student) {
            $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'type' => NotificationService::DEADLINE_CHANGED]);
        }
    }
}
