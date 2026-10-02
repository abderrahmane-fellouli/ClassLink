<?php

namespace Tests;

use App\Contracts\PdfTextExtractor;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\Quiz;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    // Laravel 11 : le trait CreatesApplication n'est plus necessaire,
    // le conteneur est cree par la classe de base.

    // -------------------------------------------------------------------------
    // Raccourcis de construction de scénarios
    // -------------------------------------------------------------------------

    protected function teacher(array $attributes = []): User
    {
        return User::factory()->teacher()->create($attributes);
    }

    protected function student(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    /**
     * Classe dont l'étudiant est membre accepté — l'état normal d'un
     * scénario d'T-12.
     */
    protected function classWithMember(?User $teacher = null, ?User $student = null): array
    {
        $teacher ??= $this->teacher();
        $student ??= $this->student();

        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        Membership::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
            'status' => 'accepted',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $teacher->id,
        ]);

        return [$classroom, $teacher, $student];
    }

    /**
     * Joue une requête avec un jeton Bearer comme en production.
     *
     * `forgetGuards()` est indispensable : sans cela, le conteneur conserve
     * l'utilisateur résolu par la requête précédente et une révocation de
     * jeton semblerait sans effet (faux positif de sécurité).
     */
    protected function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * Émet un vrai jeton Bearer pour l'utilisateur, comme le fait
     * l'authentification. Contrairement à `actingAs()`, il n'y a pas de
     * jeton transitoire : la révocation est donc réellement observable.
     */
    protected function tokenFor(User $user, string $method = 'test'): string
    {
        return app(TokenService::class)->issue($user, $method);
    }

    /**
     * Stockage en memoire pour un test hermetique.
     *
     * Le disque `testing` est ecrase a chaque test : aucun fichier d'un
     * scenario ne fuite dans le suivant, et rien ne s'accumule dans
     * `storage/framework/testing/disks`.
     */
    protected function fakeStorage(): void
    {
        Storage::fake((string) config('filesystems.default', 'local'));
    }

    /**
     * Simule un PDF lisible sans dependance native (§15.3).
     *
     * Le vrai extracteur s'appuie sur `smalot/pdf-parser` ; on remplace donc
     * le contrat pour couvrir le flux de generation sans analyse binaire.
     */
    protected function fakePdfText(string $text = 'Texte du cours de test.', int $pages = 1): void
    {
        $this->app->instance(PdfTextExtractor::class, new class($text, $pages) implements PdfTextExtractor
        {
            public function __construct(private readonly string $text, private readonly int $pages) {}

            public function extract(string $absolutePath): array
            {
                return ['text' => $this->text, 'page_count' => $this->pages];
            }
        });
    }

/**
     * Fichier déposé dont le CONTENU correspond réellement à l'extension.
     *
     * `UploadedFile::fake()->create()` produit un fichier vide et ne fait
     * que *annoncer* un type : depuis que le contrôle de dépôt lit les
     * octets (§16), un tel fichier serait refusé à raison. Ce raccourci
     * écrit donc une vraie signature (`%PDF-`, OLE2, ZIP…) suivie d'un
     * remplissage à la taille demandée.
     */
    protected function fakeUpload(string $name, ?string $mime = null, int $kilobytes = 1): \Illuminate\Http\UploadedFile
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $size = max(1, $kilobytes) * 1024;

        [$signature, $filler] = match ($extension) {
            'pdf' => ['%PDF-1.7' . "\n", '%'],
            'doc', 'xls', 'ppt' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", "\x00"],
            'docx', 'xlsx', 'pptx', 'odt', 'odp' => ["PK\x03\x04", "\x00"],
            default => ['Fichier de test.', ' '],
        };

        $content = substr($signature.str_repeat($filler, $size), 0, $size);

        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $content, $mime);
    }

    /**
     * Exécute une tache de file comme le ferait le worker, de façon
     * deterministe (independante de dispatchAfterResponse).
     */
    protected function runJob(object $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * Les scores sont des flottants cote metier, mais JSON les serialise
     * sans partie decimale (« 2 » et non « 2.0 »). On compare donc en
     * flottant plutot qu'en egalite stricte.
     */
    protected function assertScore(\Illuminate\Testing\TestResponse $response, float $expected, string $key = 'score'): void
    {
        $this->assertEqualsWithDelta($expected, (float) $response->json($key), 0.0001);
    }

    /**
     * Quiz publié d'une question à choix unique.
     */
    protected function publishedQuiz(Classroom $classroom, User $teacher, int $questions = 2): Quiz
    {
        $quiz = Quiz::create([
            'classroom_id' => $classroom->id,
            'created_by' => $teacher->id,
            'title' => 'Quiz de test',
            'status' => 'published',
            'source' => 'manual',
            'reviewed' => true,
            'time_limit_min' => 10,
            'max_attempts' => 1,
            'shuffle' => false,
            'show_answers' => true,
            'published_at' => now(),
        ]);

        for ($i = 0; $i < $questions; $i++) {
            $question = $quiz->questions()->create([
                'statement' => "Question {$i}",
                'type' => 'single',
                'explanation' => 'Explication de la question',
                'position' => $i,
            ]);

            $question->options()->create(['label' => 'Bonne réponse', 'is_correct' => true]);
            $question->options()->create(['label' => 'Mauvaise réponse', 'is_correct' => false]);
        }

        return $quiz->fresh('questions.options');
    }
}
