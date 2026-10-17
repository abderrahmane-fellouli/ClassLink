<?php

namespace App\Workspace;

/** Workflow availability only: these identifiers never grant actor permissions. */
enum Capability: string
{
    case Resources = 'resources';
    case Announcements = 'announcements';
    case Messaging = 'messaging';
    case Calendar = 'calendar';
    case Assignments = 'assignments';
    case Submissions = 'submissions';
    case Quizzes = 'quizzes';
    case Flashcards = 'flashcards';
    case AcademicYears = 'academic_years';
    case InstitutionalModules = 'institutional_modules';
    case TeachingAssignments = 'teaching_assignments';
    case OfficialGrades = 'official_grades';
    case GradeImport = 'grade_import';
    case Delegates = 'delegates';
    case Admissions = 'admissions';
    case RosterImport = 'roster_import';
    case Transfers = 'transfers';
    case StudyPartners = 'study_partners';
    case HistoricalClasses = 'historical_classes';
    case QuizProgress = 'quiz_progress';
    case AiTools = 'ai_tools';
    case AdvancedAdministration = 'advanced_administration';
    case Attendance = 'attendance';
    case ProgressTracking = 'progress_tracking';
    case Certificates = 'certificates';
    case LessonScheduling = 'lesson_scheduling';
}
