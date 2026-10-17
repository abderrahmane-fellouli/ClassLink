<?php

namespace App\Workspace;

use InvalidArgumentException;

/** Noun-form foundation only; contextual phrases stay in the existing FR/EN system. */
enum TerminologyPreset: string
{
    case Generic = 'generic';
    case Ofppt = 'ofppt';
    case LanguageSchool = 'language_school';

    /** @return array<string, array{singular: string, plural: string}> */
    public function terms(string $locale): array
    {
        $terms = match ($locale) {
            'en' => [
                'student' => ['Student', 'Students'], 'teacher' => ['Teacher', 'Teachers'],
                'class' => ['Class', 'Classes'], 'course' => ['Course', 'Courses'],
                'assignment' => ['Assignment', 'Assignments'], 'evaluation' => ['Evaluation', 'Evaluations'],
                'official_grade' => ['Grade', 'Grades'], 'progress' => ['Progress', 'Progress'],
                'administrator' => ['Administrator', 'Administrators'],
            ],
            'fr' => [
                'student' => ['Élève', 'Élèves'], 'teacher' => ['Enseignant', 'Enseignants'],
                'class' => ['Classe', 'Classes'], 'course' => ['Cours', 'Cours'],
                'assignment' => ['Devoir', 'Devoirs'], 'evaluation' => ['Évaluation', 'Évaluations'],
                'official_grade' => ['Note', 'Notes'], 'progress' => ['Progression', 'Progression'],
                'administrator' => ['Administrateur', 'Administrateurs'],
            ],
            default => throw new InvalidArgumentException('Workspace terminology supports FR/EN only.'),
        };
        if ($this === self::Ofppt) {
            $terms = array_replace($terms, $locale === 'fr' ? [
                'student' => ['Stagiaire', 'Stagiaires'], 'teacher' => ['Formateur', 'Formateurs'],
                'class' => ['Groupe', 'Groupes'], 'course' => ['Module', 'Modules'],
            ] : [
                'student' => ['Trainee', 'Trainees'], 'teacher' => ['Trainer', 'Trainers'],
                'class' => ['Group', 'Groups'], 'course' => ['Module', 'Modules'],
            ]);
        }

        return array_map(fn (array $forms) => ['singular' => $forms[0], 'plural' => $forms[1]], $terms);
    }
}
