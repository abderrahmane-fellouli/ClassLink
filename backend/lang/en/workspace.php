<?php

// New domain metadata only. Existing API/frontend vocabulary is not replaced.
return [
    'presets' => [
        'ofppt' => 'OFPPT / vocational institution', 'language_teacher' => 'Language teacher',
        'private_tutor' => 'Private tutor', 'training_center' => 'Training center',
        'university_teacher' => 'University teacher', 'general_school' => 'General school',
        'club_association' => 'Club / association', 'custom' => 'Custom',
    ],
    'capabilities' => [
        'resources' => ['label' => 'Resources', 'description' => 'Authorized teaching files and links.'],
        'announcements' => ['label' => 'Announcements', 'description' => 'Teaching announcements and audience-scoped notices.'],
        'messaging' => ['label' => 'Messaging', 'description' => 'Conversations restricted to eligible participants.'],
        'calendar' => ['label' => 'Deadlines', 'description' => 'Existing assignment and quiz deadlines, not lesson scheduling.'],
        'assignments' => ['label' => 'Assignments', 'description' => 'Teaching tasks and instructions.'],
        'submissions' => ['label' => 'Submissions', 'description' => 'Learner submissions and authorized teacher feedback.'],
        'quizzes' => ['label' => 'Practice quizzes', 'description' => 'Practice questions, attempts and results.'],
        'flashcards' => ['label' => 'Flashcards', 'description' => 'Published study decks and review history.'],
        'academic_years' => ['label' => 'Academic years', 'description' => 'Institutional academic periods and lifecycle.'],
        'institutional_modules' => ['label' => 'Institutional modules', 'description' => 'Official module catalog and offering structure.'],
        'teaching_assignments' => ['label' => 'Teaching assignments', 'description' => 'Explicit institutional teaching responsibilities.'],
        'official_grades' => ['label' => 'Official grades', 'description' => 'Authorized assessment drafts, publication and corrections.'],
        'grade_import' => ['label' => 'Grade import', 'description' => 'Validated official-grade spreadsheet import.'],
        'delegates' => ['label' => 'Representatives', 'description' => 'Time-bounded institutional student responsibilities.'],
        'admissions' => ['label' => 'Admissions', 'description' => 'Institutional admission requests and decisions.'],
        'roster_import' => ['label' => 'Roster import', 'description' => 'Validated institutional learner-roster import.'],
        'transfers' => ['label' => 'Transfers', 'description' => 'Authorized transfers preserving institutional history.'],
        'study_partners' => ['label' => 'Study partners', 'description' => 'Opt-in matching within authorized teaching groups.'],
        'historical_classes' => ['label' => 'Historical classes', 'description' => 'Existing teacher-owned class workflows and history.'],
        'quiz_progress' => ['label' => 'Quiz progress', 'description' => 'Existing quiz analytics, not flexible skill evaluation.'],
        'ai_tools' => ['label' => 'AI tools', 'description' => 'Existing historical-class draft generation; review remains required.'],
        'advanced_administration' => ['label' => 'Advanced administration', 'description' => 'Existing authorized administrative workflows; no permission grant.'],
        'attendance' => ['label' => 'Attendance', 'description' => 'Future capability: attendance registers are not implemented.'],
        'progress_tracking' => ['label' => 'Flexible progress', 'description' => 'Future capability: flexible skill evaluation is not implemented.'],
        'certificates' => ['label' => 'Certificates', 'description' => 'Future capability: certificate issuance is not implemented.'],
        'lesson_scheduling' => ['label' => 'Lesson scheduling', 'description' => 'Future capability: lesson scheduling is not implemented.'],
    ],
];
