<?php

namespace Database\Seeders;

use App\Enums\AiTarget;
use App\Enums\ClassStatus;
use App\Enums\MembershipStatus;
use App\Enums\QuizStatus;
use App\Enums\Role;
use App\Models\AiProvider;
use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\Classroom;
use App\Models\Membership;
use App\Models\Material;
use App\Models\PartnerProfile;
use App\Models\PartnerRequest;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Données de démonstration.
 *
 * §21 : la démonstration doit fonctionner sans données personnelles réelles.
 * Tous les comptes utilisent le domaine @ofppt-edu.ma et des identifiants
 * factices ; aucun nom, aucune adresse et aucune date de naissance réels ne
 * sont utilisés (RG-18, §16.1).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Génération des données de démonstration ClassLink…');

        $this->seedAiProviders();
        $this->seedAdmin();
        $this->seedTeachers();
        $this->seedClassesAndStudents();
        $this->seedQuizzes();
        $this->seedDeadlines();
        $this->seedFlashcards();

        $this->command->info('Données de démonstration prêtes.');
        $this->command->newLine();
        $this->command->table(
            ['Rôle', 'Email de démonstration', 'Mot de passe'],
            [
                ['Super admin', 'admin.classlink@ofppt-edu.ma', '(Microsoft / OTP)'],
                ['Enseignant 1', 'zakariyae.chergui@ofppt-edu.ma', '(Microsoft / OTP)'],
                ['Enseignant 2', 'hamza.bouzid@ofppt-edu.ma', '(Microsoft / OTP)'],
                ['Étudiant 1', '2007031400094@ofppt-edu.ma', '(Microsoft / OTP)'],
                ['Étudiant 2', '2007031400095@ofppt-edu.ma', '(Microsoft / OTP)'],
            ]
        );
        $this->command->info('En développement,sez aussi utiliser POST /api/auth/dev/login.');
    }

    // -------------------------------------------------------------------------

    private function seedAiProviders(): void
    {
        // §15.2 : l'ordre et les quotas sont en base, les clés dans l'env.
        $providers = [
            ['name' => 'openai', 'priority' => 1, 'daily_limit' => 20],
            ['name' => 'groq', 'priority' => 2, 'daily_limit' => 30],
            ['name' => 'mistral', 'priority' => 3, 'daily_limit' => 15],
        ];

        foreach ($providers as $provider) {
            AiProvider::updateOrCreate(
                ['name' => $provider['name']],
                $provider + ['enabled' => true, 'used_today' => 0]
            );
        }
    }

    private function seedAdmin(): User
    {
        return User::updateOrCreate(
            ['email' => 'admin.classlink@ofppt-edu.ma'],
            [
                'display_name' => 'Administrateur ClassLink',
                'role' => Role::Admin->value,
                // RG-03 : rôle verrouillé, jamais recalculé.
                'role_locked' => true,
                'locale' => 'fr',
                'is_active' => true,
            ]
        );
    }

    private function seedTeachers(): void
    {
        foreach ([
            'zakariyae.chergui@ofppt-edu.ma' => ['Zakariae', 'Chergui'],
            'hamza.bouzid@ofppt-edu.ma' => ['Hamza', 'Bouzid'],
        ] as $email => [$first, $last]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'display_name' => $first.' '.$last,
                    'role' => Role::Teacher->value,
                    'role_locked' => false,
                    'locale' => 'fr',
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedClassesAndStudents(): void
    {
        $teachers = User::where('role', Role::Teacher->value)->orderBy('id')->get();

        $classes = [
            [
                'name' => 'Développement Web — TDI 1',
                'subject' => 'Développement Web',
                'group_label' => 'TDI 1',
                'code' => 'TDI2025A',
            ],
            [
                'name' => 'Bases de données — TDI 2',
                'subject' => 'Bases de données',
                'group_label' => 'TDI 2',
                'code' => 'BDD2025B',
            ],
            [
                'name' => 'Réseaux informatiques — TDI 3',
                'subject' => 'Réseaux',
                'group_label' => 'TDI 3',
                'code' => 'RES2025C',
            ],
            [
                'name' => 'Algorithmique — TDI 1 (année précédente)',
                'subject' => 'Algorithmique',
                'group_label' => 'TDI 1',
                'code' => 'ALG2024D',
                'status' => ClassStatus::Archived->value,
            ],
        ];

        $created = [];

        foreach ($classes as $index => $definition) {
            $teacher = $teachers[$index % $teachers->count()];

            $classroom = Classroom::updateOrCreate(
                ['join_code' => $definition['code']],
                [
                    'teacher_id' => $teacher->id,
                    'name' => $definition['name'],
                    'subject' => $definition['subject'],
                    'group_label' => $definition['group_label'],
                    'school_year' => $index === 3 ? '2024-2025' : '2025-2026',
                    'join_enabled' => ($definition['status'] ?? 'active') === 'active',
                    'status' => $definition['status'] ?? ClassStatus::Active->value,
                    'archived_at' => ($definition['status'] ?? null) === ClassStatus::Archived->value ? now() : null,
                ]
            );

            $created[] = $classroom;
        }

        $this->seedStudents($created);
    }

    private function seedStudents(array $classes): void
    {
        $firstNames = ['Yassine', 'Salma', 'Amine', 'Imane', 'Oussama', 'Nour', 'Karim', 'Hiba',
            'Reda', 'Meryem', 'Anas', 'Sanaa', 'Younes', 'Aya', 'Bilal', 'Ghita',
            'Mehdi', 'Lina', 'Tarek', 'Rim', 'Adam', 'Sara', 'Nabil', 'Ines'];

        $lastNames = ['Alaoui', 'Benali', 'Chraibi', 'El Fassi', 'Fantini', 'Guerbaoui', 'Haddad', 'Idrissi',
            'Jabri', 'Kettani', 'Lamrani', 'Mansouri', 'Naciri', 'Ouazzani', 'Ouazzani', 'Sabri',
            'Tahiri', 'Zniber', 'Amrani', 'Belkadi', 'Cherkaoui', 'Daoudi', 'El Ghazi', 'Farid'];

        $students = collect();

        foreach ($firstNames as $i => $first) {
            $number = '20070314000'.(94 + $i); // 13 chiffres (RG-02)
            $last = $lastNames[$i % count($lastNames)];

            $students->push(User::updateOrCreate(
                ['email' => $number.'@ofppt-edu.ma'],
                [
                    'display_name' => $first.' '.$last,
                    'role' => Role::Student->value,
                    'role_locked' => false,
                    'locale' => $i % 5 === 0 ? 'en' : 'fr',
                    'is_active' => true,
                ]
            ));
        }

        // Répartition des adhésions : statuts variés pour la démonstration
        // de F-REQ-03 à F-REQ-08 (pending, accepted, rejected).
        foreach ($classes as $classIndex => $classroom) {
            if ($classroom->isArchived()) {
                continue;
            }

            $members = $students->slice($classIndex * 8, 8);

            $members->each(function (User $student, int $i) use ($classroom) {
                $status = match (true) {
                    $i < 5 => MembershipStatus::Accepted,
                    $i === 5 => MembershipStatus::Pending,
                    $i === 6 => MembershipStatus::Rejected,
                    default => MembershipStatus::Accepted,
                };

                Membership::updateOrCreate(
                    ['classroom_id' => $classroom->id, 'student_id' => $student->id],
                    [
                        'status' => $status->value,
                        'requested_at' => Carbon::now()->subDays(10 - $i),
                        'decided_at' => in_array($status, [MembershipStatus::Accepted, MembershipStatus::Rejected], true)
                            ? Carbon::now()->subDays(2)
                            : null,
                        'decided_by' => $classroom->teacher_id,
                    ]
                );
            });
        }

        $this->seedNotifications($students, $classes);
        $this->seedPartnerProfiles($students);
    }

    /**
     * F-PAR-01 / F-PAR-02 — profils partenaires de démonstration.
     *
     * RG-17 : l'inscription est volontaire, donc seuls certains étudiants
     * l'activent. Les compétences et disponibilités sont fictives et servent
     * à obtenir un score de compatibilité non nul dans la maquette.
     */
    private function seedPartnerProfiles($students): void
    {
        $skillPools = [
            ['PHP', 'SQL', 'JavaScript'],
            ['SQL', 'Python'],
            ['JavaScript', 'HTML/CSS'],
            ['Python', 'Java'],
            ['SQL', 'PHP', 'C#'],
        ];

        $slotPools = [
            ['Mardi 14h', 'Jeudi 10h'],
            ['Lundi 09h', 'Jeudi 10h'],
            ['Jeudi 10h', 'Vendredi 08h'],
            ['Mardi 14h', 'Mercredi 16h'],
        ];

        $students->each(function (User $student, int $i) use ($skillPools, $slotPools) {
            // Un étudiant sur trois reste désinscrit : la désinscription
            // doit être visible dans la liste des candidats (RG-17).
            $optIn = $i % 3 !== 2;

            PartnerProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'opt_in' => $optIn,
                    'skills' => $optIn ? $skillPools[$i % count($skillPools)] : [],
                    'availability' => $optIn ? $slotPools[$i % count($slotPools)] : [],
                ]
            );
        });

        $this->seedPartnerRequest();
    }

    /** F-PAR-03 — une demande en attente pour illustrer le suivi. */
    private function seedPartnerRequest(): void
    {
        $members = Membership::where('status', MembershipStatus::Accepted->value)
            ->whereHas('classroom', fn ($query) => $query->where('status', ClassStatus::Active->value))
            ->get()
            ->groupBy('classroom_id')
            ->first();

        if (! $members || $members->count() < 2) {
            return;
        }

        [$from, $to] = $members->values()->all();

        PartnerRequest::updateOrCreate(
            [
                'from_user_id' => $from->student_id,
                'to_user_id' => $to->student_id,
                'classroom_id' => $from->classroom_id,
            ],
            ['status' => 'pending']
        );
    }

    private function seedNotifications($students, array $classes): void
    {
        if ($students->isEmpty() || $classes === []) {
            return;
        }

        AppNotification::firstOrCreate(
            ['user_id' => $students->first()->id, 'type' => 'membership_accepted'],
            [
                'payload' => [
                    'classroom_name' => $classes[0]->name,
                    'message' => 'Votre demande a été acceptée.',
                ],
            ]
        );

        AppNotification::firstOrCreate(
            ['user_id' => $students->first()->id, 'type' => 'join_requested'],
            [
                'payload' => [
                    'classroom_name' => $classes[0]->name,
                    'message' => 'Un camarade a demandé à rejoindre votre classe.',
                ],
            ]
        );
    }

    private function seedQuizzes(): void
    {
        $classes = Classroom::where('status', 'active')->get();

        foreach ($classes as $classroom) {
            // Quiz publié — F-QUI-03.
            $published = Quiz::updateOrCreate(
                ['classroom_id' => $classroom->id, 'title' => 'Quiz — '.$classroom->subject.' (évaluation 1)'],
                [
                    'created_by' => $classroom->teacher_id,
                    'status' => QuizStatus::Published->value,
                    'source' => 'manual',
                    'reviewed' => true,
                    'time_limit_min' => 15,
                    'max_attempts' => 2,
                    'shuffle' => true,
                    'show_answers' => true,
                    'published_at' => Carbon::now()->subDays(7),
                ]
            );

            $this->seedQuestions($published, 5);

            // Brouillon IA non relu : F-IA-03 / RG-11 — non publiable.
            $aiDraft = Quiz::updateOrCreate(
                ['classroom_id' => $classroom->id, 'title' => 'Brouillon IA — '.$classroom->subject],
                [
                    'created_by' => $classroom->teacher_id,
                    'status' => QuizStatus::Draft->value,
                    'source' => 'ai',
                    'reviewed' => false,
                    'time_limit_min' => 20,
                    'max_attempts' => 1,
                    'shuffle' => false,
                    'show_answers' => true,
                ]
            );

            $this->seedQuestions($aiDraft, 3);

            $this->seedAnnouncements($classroom);
            $this->seedAttempts($published, $classroom);
        }
    }

    private function seedQuestions(Quiz $quiz, int $count): void
    {
        if ($quiz->questions()->exists()) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $question = $quiz->questions()->create([
                'statement' => match ($i % 3) {
                    0 => 'Quelle est la définition d\'une variable en algorithmique ?',
                    1 => 'Quel mot-clé SQL sert à filtrer les lignes d\'une requête ?',
                    default => 'Vrai ou faux : une classe abstraite peut être instanciée directement.',
                },
                'type' => match ($i % 3) {
                    0 => 'single',
                    1 => 'single',
                    default => 'true_false',
                },
                'explanation' => 'Vérifiez la définition dans le chapitre correspondant du cours.',
                'position' => $i,
            ]);

            $options = $i % 3 === 2
                ? [['label' => 'Vrai', 'is_correct' => true], ['label' => 'Faux', 'is_correct' => false]]
                : [
                    ['label' => 'Une valeur nommée et modifiable', 'is_correct' => true],
                    ['label' => 'Une constante immuable', 'is_correct' => false],
                    ['label' => 'Une fonction récursive', 'is_correct' => false],
                ];

            foreach ($options as $option) {
                $question->options()->create($option);
            }
        }
    }

    private function seedAnnouncements(Classroom $classroom): void
    {
        if ($classroom->announcements()->exists()) {
            return;
        }

        $classroom->announcements()->create([
            'author_id' => $classroom->teacher_id,
            'title' => 'Rappel : évaluation diagnostique',
            'body' => "La prochaine évaluation portera sur les chapitres 1 à 3.\n"
                ."Merci de revising le cours et les exercices du cahier.",
            'pinned' => true,
        ]);

        $classroom->announcements()->create([
            'author_id' => $classroom->teacher_id,
            'title' => 'Soutien scolaire le mardi',
            'body' => 'Une séance de soutien aura lieu mardi de 14h à 16h en salle B12.',
            'pinned' => false,
        ]);
    }

    private function seedAttempts(Quiz $quiz, Classroom $classroom): void
    {
        if ($quiz->attempts()->exists()) {
            return;
        }

        $questions = $quiz->questions()->with('options')->get();

        $members = $classroom->memberships()
            ->where('status', MembershipStatus::Accepted->value)
            ->pluck('student_id');

        foreach ($members->take(5) as $studentId) {
            $attempt = Attempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $studentId,
                'attempt_no' => 1,
                'max_score' => $questions->count(),
                'started_at' => Carbon::now()->subDays(6),
                'submitted_at' => Carbon::now()->subDays(6)->addMinutes(12),
            ]);

            $score = 0;

            foreach ($questions as $question) {
                $correctIds = $question->correctOptionIds();
                $isCorrect = $index = $question->position % 2 === 0;
                $selected = $isCorrect ? $correctIds : [$question->options->first()->id];

                if ($isCorrect) {
                    $score++;
                }

                $attempt->answers()->create([
                    'question_id' => $question->id,
                    'selected_option_ids' => $selected,
                    'is_correct' => $isCorrect,
                    'awarded_score' => $isCorrect ? 1 : 0,
                ]);
            }

            $attempt->update(['score' => $score]);
        }
    }

    private function seedDeadlines(): void
    {
        $classes = Classroom::where('status', 'active')->get();

        foreach ($classes as $classroom) {
            Assignment::updateOrCreate(
                ['classroom_id' => $classroom->id, 'title' => 'TP — '.$classroom->subject],
                [
                    'created_by' => $classroom->teacher_id,
                    'instructions' => 'Déposez votre fichier HTML/CSS avant la date limite.',
                    'due_at' => Carbon::now()->addDays(7),
                ]
            );

            Assignment::updateOrCreate(
                ['classroom_id' => $classroom->id, 'title' => 'Exercice — requêtes SQL'],
                [
                    'created_by' => $classroom->teacher_id,
                    'instructions' => 'Rédigez cinq requêtes correspondant à l\'énoncé.',
                    'due_at' => Carbon::now()->subDays(2), // déjà échu -> « en retard »
                ]
            );
        }
    }

    private function seedFlashcards(): void
    {
        $classes = Classroom::where('status', 'active')->get();

        foreach ($classes as $classroom) {
            $deck = \App\Models\FlashcardDeck::updateOrCreate(
                ['classroom_id' => $classroom->id, 'title' => 'Révision — notions clés'],
                [
                    'source' => 'manual',
                    'status' => 'published',
                    'reviewed' => true,
                ]
            );

            if ($deck->cards()->exists()) {
                continue;
            }

            $cards = [
                ['front' => 'Qu\'est-ce qu\'une variable ?', 'back' => 'Une zone de mémoire nommée, typée, dont la valeur peut changer.'],
                ['front' => 'Mot-clé de filtrage en SQL', 'back' => 'WHERE.'],
                ['front' => 'Que fait GROUP BY ?', 'back' => 'Regroupe les lignes ayant des valeurs communes dans une colonne.'],
                ['front' => 'Différence entre == et ===', 'back' => '== compare après conversion de type, === compare type et valeur.'],
            ];

            foreach ($cards as $i => $card) {
                $deck->cards()->create([
                    'front' => $card['front'],
                    'back' => $card['back'],
                    'position' => $i,
                ]);
            }
        }
    }
}
