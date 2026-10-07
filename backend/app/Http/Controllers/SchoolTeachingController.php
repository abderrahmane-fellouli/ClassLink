<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizRequest;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\FlashcardDeck;
use App\Models\Material;
use App\Models\Quiz;
use App\Services\OfficialGradeService;
use App\Services\SchoolAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Reuse the existing validated teaching workflows inside a verified module scope. */
class SchoolTeachingController extends Controller
{
    public function __construct(private SchoolAccess $access) {}

    private function scope(Request $r, int $offering, bool $write = false)
    {
        $o = $this->access->offering($r->user(), $offering, $write);
        $r->attributes->set('school_offering_id', $offering);
        if ($r->filled('due_at') && ! preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/', (string) $r->input('due_at'))) {
            validator(['due_at' => $r->input('due_at')], ['due_at' => ['nullable', 'date']])->validate();
            $r->merge(['due_at' => Carbon::parse($r->input('due_at'), 'Africa/Casablanca')->utc()->toIso8601String()]);
        }
        $group = $this->access->group($o->classroom_id);
        if (! $r->isMethod('get')) {
            $this->access->writable($group);
        }
        $r->route()->setParameter('classroom', $group);

        return $group;
    }

    public function collection(Request $r, int $offering, string $kind)
    {
        $group = $this->scope($r, $offering, $r->isMethod('post'));
        $read = ['materials' => [ContentController::class, 'materials'], 'announcements' => [ContentController::class, 'announcements'],
            'assignments' => [AssignmentController::class, 'index'], 'quizzes' => [QuizController::class, 'index'], 'flashcards' => [FlashcardController::class, 'index']];
        $write = ['materials' => [ContentController::class, 'storeMaterial'], 'announcements' => [ContentController::class, 'storeAnnouncement'],
            'assignments' => [AssignmentController::class, 'store'], 'flashcards' => [FlashcardController::class, 'store']];
        if ($r->isMethod('post') && $kind === 'quizzes') {
            $form = StoreQuizRequest::createFrom($r);
            $form->setContainer(app())->setRedirector(app('redirect'));
            $form->validateResolved();

            return DB::transaction(fn () => app(QuizController::class)->store($form, $group));
        }
        $call = ($r->isMethod('post') ? $write : $read)[$kind] ?? null;
        abort_unless($call, 404);

        return $r->isMethod('post') ? DB::transaction(fn () => app($call[0])->{$call[1]}($r, $group)) : app($call[0])->{$call[1]}($r, $group);
    }

    public function record(Request $r, int $offering, string $kind, int $record, string $action = 'show')
    {
        $this->scope($r, $offering, ! $r->isMethod('get') && ! in_array($action, ['submit', 'attempt', 'answers']));
        $models = ['materials' => Material::class, 'assignments' => Assignment::class, 'quizzes' => Quiz::class, 'flashcards' => FlashcardDeck::class];
        abort_unless(isset($models[$kind]), 404);
        $object = $models[$kind]::where('offering_id', $offering)->findOrFail($record);
        $r->route()->setParameter(match ($kind) {
            'quizzes' => 'quiz', 'flashcards' => 'deck', 'materials' => 'material', default => 'assignment'
        }, $object);
        if ($kind === 'assignments' && in_array($action, ['publish', 'close'])) {
            abort_unless($r->isMethod('post'), 405);
            $this->access->offering($r->user(), $offering, true);
            $object->forceFill(['publication_status' => $action === 'publish' ? 'published' : 'closed'])->save();
            if ($action === 'publish') {
                foreach (DB::table('school_enrollments')->where('classroom_id', $object->classroom_id)->whereNotNull('active_student_id')->pluck('student_id') as $student) {
                    DB::table('school_notification_outbox')->insertOrIgnore(['event_key' => 'assignment:'.$record, 'user_id' => $student, 'type' => 'assignment_published',
                        'payload' => json_encode(['assignment_id' => $record, 'url' => '/app/assignments/'.$record]), 'created_at' => now(), 'updated_at' => now()]);
                }
                app(OfficialGradeService::class)->queueNotifications();
            }
            AuditLog::record($r->user(), 'school.assignment.'.$action, ['assignment_id' => $record]);

            return response()->json(['id' => $record, 'publication_status' => $object->publication_status]);
        }
        if ($kind === 'assignments' && $action === 'submit') {
            abort_unless($object->publication_status === 'published', 409);
        }
        $controller = ['materials' => ContentController::class, 'assignments' => AssignmentController::class, 'quizzes' => QuizController::class, 'flashcards' => FlashcardController::class][$kind];
        $maps = [
            'materials' => ['GET:download' => 'download', 'PATCH:show' => 'updateMaterial', 'DELETE:show' => 'destroyMaterial'],
            'assignments' => ['GET:show' => 'show', 'PATCH:show' => 'update', 'DELETE:show' => 'destroy', 'POST:submit' => 'submit', 'GET:submissions' => 'submissions'],
            'quizzes' => ['GET:show' => 'show', 'PATCH:show' => 'update', 'DELETE:show' => 'destroy', 'POST:publish' => 'publish', 'POST:review' => 'review', 'POST:attempt' => 'startAttempt', 'GET:active-attempt' => 'activeAttempt'],
            'flashcards' => ['GET:show' => 'show', 'PATCH:show' => 'update', 'DELETE:show' => 'destroy', 'POST:publish' => 'publish', 'POST:reviewed' => 'markReviewed'],
        ];
        $method = $maps[$kind][$r->method().':'.$action] ?? null;
        abort_unless($method, 404);

        // Some controller actions take only the resource, not a Request.
        return app()->call([app($controller), $method], ['request' => $r, 'material' => $object, 'assignment' => $object, 'quiz' => $object, 'deck' => $object]);
    }
}
