<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DevAuthController;
use App\Http\Controllers\FlashcardController;
use App\Http\Controllers\JoinRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProgressionController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizResultController;
use App\Jobs\PruneExpiredOtpCodes;
use App\Services\AiService;
use App\Services\EmailDigestService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API ClassLink — §12
|--------------------------------------------------------------------------
| Toutes les routes sont préfixées par /api, échangent du JSON et exigent
| l'en-tête `Authorization: Bearer <jeton>` sauf mention contraire.
|
| L'autorisation est vérifiée côté serveur : le rôle par middleware
| `role:...`, puis la propriété ou l'adhésion acceptée par les policies.
| Un rôle n'est jamais accepté depuis le navigateur (§16).
|
*/

// ===========================================================================
// 12.1 Authentification — public
// ===========================================================================

/*
 | T-26 : la langue s'applique aussi aux écrans publics (page de connexion).
 | Le middleware est ajouté après `throttle` pour ne pas le payer sur les
 | requêtes déjà limitées par le limiteur de débit.
 */
Route::middleware('locale')->group(function () {

    Route::get('/auth/microsoft/redirect', [AuthController::class, 'redirect'])
        ->middleware('throttle:'.config('classlink.throttle.oauth_redirect'));

    Route::post('/auth/microsoft/pending-verification', [AuthController::class, 'pendingVerification'])
        ->middleware('throttle:'.config('classlink.throttle.oauth_redirect'));
    Route::get('/auth/microsoft/callback', [AuthController::class, 'callback'])
        ->middleware('throttle:'.config('classlink.throttle.oauth_redirect'));

    // F-AUTH-02 : connexion de secours. RG-01 vérifié par la Form Request.
    Route::post('/auth/otp/request', [OtpController::class, 'request'])
        ->middleware('throttle:'.config('classlink.throttle.otp_request'));

    Route::post('/auth/otp/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:'.config('classlink.throttle.otp_verify'));

    // §25 — développement uniquement. La route est absente de la production.
    Route::post('/auth/dev/login', [DevAuthController::class, 'login'])
        ->middleware('throttle:30,1');

});

// Route protégée appelée par la tâche planifiée GitHub Actions (§17.11).
Route::post('/internal/daily-digest', function (EmailDigestService $digest) {
    return response()->json(['sent' => $digest->sendDailyDigest()]);
})->middleware('digest.token');

Route::post('/internal/prune', function (PruneExpiredOtpCodes $job) {
    // `app()->call()` resout les dependances de `handle()` comme le worker.
    return response()->json(['deleted' => app()->call([$job, 'handle'])]);
})->middleware('digest.token');

Route::post('/internal/finalize-attempts', function (\App\Services\QuizGradingService $grading) {
    return response()->json(['finalized' => $grading->finalizeExpired()]);
})->middleware('digest.token');

/*
 * F-IA-05 — remise a zero quotidienne des quotas IA (RG-13).
 *
 * `routes/console.php` enregistre deja cette tache via `Schedule::call`, mais
 * aucun `schedule:run` ne s'execute en production : sans declencheur, le quota
 * d'un enseignant reste epuise definitivement des le premier jour. La tache est
 * donc exposee sur le meme modele que le resume et la purge, et declenchee par
 * `.github/workflows/ai-quota-reset.yml` (00:05 UTC).
 */
Route::post('/internal/ai-quota-reset', function (AiService $ai) {
    return response()->json(['reset' => $ai->resetDailyQuotas()]);
})->middleware('digest.token');

// ===========================================================================
// Authentifié — §12
// ===========================================================================

Route::middleware(['auth:sanctum', 'active', 'locale'])->group(function () {

    // --- 12.1 Profil ------------------------------------------------------
    Route::get('/me', [ProfileController::class, 'show']);
    Route::get('/me/announcements', [ContentController::class, 'myAnnouncements'])->middleware('role:student');
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::delete('/me/sessions', [ProfileController::class, 'destroySessions']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // --- 12.2 Classes et adhésions ---------------------------------------
    Route::get('/classes', [ClassroomController::class, 'index']);
    Route::post('/classes', [ClassroomController::class, 'store'])
        ->middleware('role:teacher,admin');

    Route::get('/classes/{classroom}', [ClassroomController::class, 'show']);
    Route::patch('/classes/{classroom}', [ClassroomController::class, 'update']);
    Route::post('/classes/{classroom}/archive', [ClassroomController::class, 'archive']);
    Route::post('/classes/{classroom}/code/regenerate', [ClassroomController::class, 'regenerateCode']);
    Route::post('/classes/{classroom}/code/toggle', [ClassroomController::class, 'toggleCode']);
    Route::get('/classes/{classroom}/members', [ClassroomController::class, 'members']);
    Route::delete('/classes/{classroom}/members/{studentId}', [ClassroomController::class, 'removeMember']);
    Route::get('/classes/{classroom}/join-requests', [ClassroomController::class, 'joinRequests']);
    Route::post('/classes/{classroom}/join-requests/accept-all', [ClassroomController::class, 'acceptAll']);
    // F-REQ-09 (Could) — import CSV.
    Route::post('/classes/{classroom}/members/import', [ClassroomController::class, 'importMembers']);

    // §17.7 : `role:student` sur la demande d'adhésion.
    Route::post('/join-requests', [JoinRequestController::class, 'store'])
        ->middleware('role:student')
        ->middleware('throttle:'.config('classlink.throttle.join_request'));

    Route::get('/join-requests/mine', [JoinRequestController::class, 'mine'])
        ->middleware('role:student');

    Route::post('/join-requests/{membership}/accept', [ClassroomController::class, 'accept']);
    Route::post('/join-requests/{membership}/reject', [ClassroomController::class, 'reject']);

    // --- 12.3 Contenus ----------------------------------------------------
    Route::get('/classes/{classroom}/materials', [ContentController::class, 'materials']);
    Route::post('/classes/{classroom}/materials', [ContentController::class, 'storeMaterial']);
    Route::get('/materials/{material}/download', [ContentController::class, 'download']);
    Route::patch('/materials/{material}', [ContentController::class, 'updateMaterial']);
    Route::delete('/materials/{material}', [ContentController::class, 'destroyMaterial']);

    Route::get('/classes/{classroom}/announcements', [ContentController::class, 'announcements']);
    Route::post('/classes/{classroom}/announcements', [ContentController::class, 'storeAnnouncement']);
    Route::patch('/announcements/{announcement}', [ContentController::class, 'updateAnnouncement']);
    Route::delete('/announcements/{announcement}', [ContentController::class, 'destroyAnnouncement']);

    // --- 12.4 Quiz --------------------------------------------------------
    Route::get('/classes/{classroom}/quizzes', [QuizController::class, 'index']);
    Route::post('/classes/{classroom}/quizzes', [QuizController::class, 'store']);

    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::get('/quizzes/{quiz}/editor', [QuizController::class, 'openEditor']);
    Route::patch('/quizzes/{quiz}', [QuizController::class, 'update']);
    Route::delete('/quizzes/{quiz}', [QuizController::class, 'destroy']);
    Route::post('/quizzes/{quiz}/publish', [QuizController::class, 'publish']);
    Route::post('/quizzes/{quiz}/review', [QuizController::class, 'review']);

    // F-QUI-01 / US-25 — questions d'un quiz en brouillon. Le controle
    // d'acces et le statut « brouillon » sont appliques par
    // `QuizPolicy::manageQuestions` via `QuestionRequest::authorize()`.
    Route::post('/quizzes/{quiz}/questions', [QuestionController::class, 'store']);
    Route::post('/quizzes/{quiz}/questions/reorder', [QuestionController::class, 'reorder']);
    Route::patch('/questions/{question}', [QuestionController::class, 'update']);
    Route::delete('/questions/{question}', [QuestionController::class, 'destroy']);

    Route::post('/quizzes/{quiz}/attempts', [QuizController::class, 'startAttempt'])
        ->middleware('role:student');

    // F-QUI-04 : reprise d'une tentative déjà commencée (autre navigateur,
    // rechargement). Doit précéder `/attempts/{attempt}` pour ne pas être
    // capté par le paramètre implicite.
    Route::get('/quizzes/{quiz}/attempts/active', [QuizController::class, 'activeAttempt'])
        ->middleware('role:student');

    Route::post('/attempts/{attempt}/submit', [QuizController::class, 'submitAttempt'])
        ->middleware('role:student');
    Route::patch('/attempts/{attempt}/answers', [QuizController::class, 'saveAttemptAnswers'])->middleware('role:student');
    Route::get('/attempts/{attempt}', [QuizController::class, 'showAttempt'])
        ->middleware('role:student');

    // F-QUI-07 / F-QUI-09
    Route::get('/quizzes/{quiz}/results', [QuizResultController::class, 'show']);
    Route::get('/quizzes/{quiz}/results/export', [QuizResultController::class, 'exportCsv']);

    // --- 12.4 Intelligence artificielle ------------------------------------
    Route::post('/classes/{classroom}/ai/generate', [AiController::class, 'generate'])
        ->middleware('role:teacher')
        ->middleware('throttle:'.config('classlink.throttle.ai_generate'));

    Route::get('/ai/jobs/{aiJob}', [AiController::class, 'job']);

    // --- 12.5 Devoirs, progression, partenaires, notifications ------------
    Route::get('/classes/{classroom}/assignments', [AssignmentController::class, 'index']);
    Route::post('/classes/{classroom}/assignments', [AssignmentController::class, 'store']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);

    Route::post('/assignments/{assignment}/submissions', [AssignmentController::class, 'submit'])
        ->middleware('role:student');
    Route::get('/assignments/{assignment}/submissions', [AssignmentController::class, 'submissions']);
    Route::patch('/submissions/{submission}', [AssignmentController::class, 'grade']);
    Route::get('/submissions/{submission}/download', [AssignmentController::class, 'downloadSubmission']);

    // F-DEV-04 : calendrier des échéances (devoirs et quiz).
    Route::get('/me/deadlines', [\App\Http\Controllers\DeadlineController::class, 'index'])->middleware('role:student');
    Route::get('/me/notification-preferences', [NotificationController::class, 'preferences']);
    Route::put('/me/notification-preferences', [NotificationController::class, 'updatePreferences']);

    Route::get('/me/progress', [ProgressionController::class, 'me'])->middleware('role:student');
    Route::get('/classes/{classroom}/progress', [ProgressionController::class, 'classroom']);

    Route::get('/me/partner-profile', [PartnerController::class, 'profile'])->middleware('role:student');
    Route::put('/me/partner-profile', [PartnerController::class, 'updateProfile'])->middleware('role:student');
    Route::get('/classes/{classroom}/partners', [PartnerController::class, 'candidates'])->middleware('role:student');
    Route::get('/me/partner-requests', [PartnerController::class, 'myRequests'])->middleware('role:student');
    Route::post('/partner-requests', [PartnerController::class, 'request'])->middleware('role:student');
    Route::post('/partner-requests/{partnerRequest}/respond', [PartnerController::class, 'respond'])
        ->middleware('role:student');

    // F-QUI-08 — flashcards
    Route::get('/classes/{classroom}/flashcards', [FlashcardController::class, 'index']);
    Route::post('/classes/{classroom}/flashcards', [FlashcardController::class, 'store']);
    Route::get('/flashcard-decks/{deck}', [FlashcardController::class, 'show']);
    Route::patch('/flashcard-decks/{deck}', [FlashcardController::class, 'update']);
    Route::delete('/flashcard-decks/{deck}', [FlashcardController::class, 'destroy']);
    Route::patch('/flashcard-decks/{deck}/cards/{card}', [FlashcardController::class, 'updateCard']);
    Route::delete('/flashcard-decks/{deck}/cards/{card}', [FlashcardController::class, 'destroyCard']);
    // Relecture d'une carte par l'eleute (F-QUI-08) — distincte de
    // `/reviewed` qui marque le deck entier comme relu par l'enseignant.
    Route::post('/flashcard-decks/{deck}/cards/{card}/review', [FlashcardController::class, 'review']);
    Route::post('/flashcard-decks/{deck}/publish', [FlashcardController::class, 'publish']);
    Route::post('/flashcard-decks/{deck}/reviewed', [FlashcardController::class, 'markReviewed']);

    // F-NOT-01
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // --- 12.6 Administration (F-ADM-01 à F-ADM-06) -----------------------
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/pending', [AdminController::class, 'pendingUsers']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);

        Route::get('/classes', [AdminController::class, 'classes']);
        Route::post('/classes/{classroom}/transfer', [AdminController::class, 'transferClass']);
        Route::post('/classes/{classroom}/archive', [AdminController::class, 'archiveClass']);

        Route::get('/ai/providers', [AdminController::class, 'aiProviders']);
        Route::patch('/ai/providers/{provider}', [AdminController::class, 'updateAiProvider']);
        Route::post('/ai/providers/{provider}/reset-quota', [AdminController::class, 'resetAiQuota']);

        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
        Route::get('/stats', [AdminController::class, 'stats']);
    });
});
