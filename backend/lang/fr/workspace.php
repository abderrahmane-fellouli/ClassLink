<?php

// Métadonnées du nouveau domaine uniquement ; aucun remplacement du vocabulaire existant.
return [
    'presets' => [
        'ofppt' => 'OFPPT / établissement de formation professionnelle', 'language_teacher' => 'Enseignant de langues',
        'private_tutor' => 'Professeur particulier', 'training_center' => 'Centre de formation',
        'university_teacher' => 'Enseignant universitaire', 'general_school' => 'Établissement scolaire',
        'club_association' => 'Club / association', 'custom' => 'Personnalisé',
    ],
    'capabilities' => [
        'resources' => ['label' => 'Ressources', 'description' => 'Fichiers et liens pédagogiques accessibles selon les autorisations.'],
        'announcements' => ['label' => 'Annonces', 'description' => 'Annonces pédagogiques et communications à audience définie.'],
        'messaging' => ['label' => 'Messagerie', 'description' => 'Conversations réservées aux participants éligibles.'],
        'calendar' => ['label' => 'Échéances', 'description' => 'Échéances existantes des devoirs et quiz, sans planification de séances.'],
        'assignments' => ['label' => 'Devoirs', 'description' => 'Travaux pédagogiques et consignes.'],
        'submissions' => ['label' => 'Rendus', 'description' => 'Dépôts des apprenants et retours des enseignants autorisés.'],
        'quizzes' => ['label' => 'Quiz d’entraînement', 'description' => 'Questions, tentatives et résultats d’entraînement.'],
        'flashcards' => ['label' => 'Flashcards', 'description' => 'Paquets de révision publiés et historique de révision.'],
        'academic_years' => ['label' => 'Années scolaires', 'description' => 'Périodes institutionnelles et cycle de vie scolaire.'],
        'institutional_modules' => ['label' => 'Modules institutionnels', 'description' => 'Catalogue officiel des modules et structure des offres.'],
        'teaching_assignments' => ['label' => 'Affectations pédagogiques', 'description' => 'Responsabilités institutionnelles explicites des enseignants.'],
        'official_grades' => ['label' => 'Notes officielles', 'description' => 'Brouillons, publication et corrections des évaluations autorisées.'],
        'grade_import' => ['label' => 'Import des notes', 'description' => 'Import validé de tableaux de notes officielles.'],
        'delegates' => ['label' => 'Délégués', 'description' => 'Responsabilités institutionnelles des apprenants limitées dans le temps.'],
        'admissions' => ['label' => 'Admissions', 'description' => 'Demandes et décisions d’admission institutionnelle.'],
        'roster_import' => ['label' => 'Import des effectifs', 'description' => 'Import validé des listes institutionnelles d’apprenants.'],
        'transfers' => ['label' => 'Transferts', 'description' => 'Transferts autorisés préservant l’historique institutionnel.'],
        'study_partners' => ['label' => 'Partenaires d’étude', 'description' => 'Mise en relation volontaire dans les groupes autorisés.'],
        'historical_classes' => ['label' => 'Classes historiques', 'description' => 'Fonctions et historique des classes existantes gérées par un enseignant.'],
        'quiz_progress' => ['label' => 'Progression des quiz', 'description' => 'Analyses existantes des quiz, sans évaluation flexible des compétences.'],
        'ai_tools' => ['label' => 'Outils IA', 'description' => 'Génération existante de brouillons en classe historique, avec relecture obligatoire.'],
        'advanced_administration' => ['label' => 'Administration avancée', 'description' => 'Opérations administratives existantes autorisées, sans attribution de permissions.'],
        'attendance' => ['label' => 'Présences', 'description' => 'Fonction future : les registres de présence ne sont pas implémentés.'],
        'progress_tracking' => ['label' => 'Progression flexible', 'description' => 'Fonction future : l’évaluation flexible des compétences n’est pas implémentée.'],
        'certificates' => ['label' => 'Certificats', 'description' => 'Fonction future : la délivrance de certificats n’est pas implémentée.'],
        'lesson_scheduling' => ['label' => 'Planification des séances', 'description' => 'Fonction future : la planification des séances n’est pas implémentée.'],
    ],
];
