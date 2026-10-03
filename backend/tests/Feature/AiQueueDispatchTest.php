<?php

namespace Tests\Feature;

use App\Models\AiJob;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiQueueDispatchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_database_queue_defers_work_until_a_worker_runs(): void
    {
        $this->fakeStorage();
        $this->fakePdfText();
        Http::fake();
        [$class, $teacher] = $this->classWithMember();
        $file = $this->fakeUpload('course.pdf');
        $hash = hash_file('sha256', $file->getRealPath());
        Cache::put("ai:quiz:$hash", ['title' => 'Queued draft', 'questions' => [[
            'statement' => 'Question?', 'type' => 'single', 'options' => [
                ['label' => 'Yes', 'is_correct' => true], ['label' => 'No', 'is_correct' => false],
            ],
        ]]], 60);

        $id = $this->actingAs($teacher)->post("/api/classes/{$class->id}/ai/generate", ['file' => $file])
            ->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('quizzes', 0);
        Http::assertNothingSent();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame('done', AiJob::findOrFail($id)->status->value);
        $this->assertDatabaseHas('quizzes', ['title' => 'Queued draft', 'status' => 'draft', 'reviewed' => false]);
        $this->assertDatabaseCount('jobs', 0);
    }
}
