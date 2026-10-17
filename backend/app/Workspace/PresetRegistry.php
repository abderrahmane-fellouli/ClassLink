<?php

namespace App\Workspace;

/** Versioned recommendations only. No workspace provisioning or production defaults. */
final class PresetRegistry
{
    public static function get(Preset $preset): PresetDefinition
    {
        $recommendations = match ($preset) {
            Preset::Ofppt => array_values(array_filter(Capability::cases(), CapabilityRegistry::isImplemented(...))),
            Preset::LanguageTeacher => [Capability::Resources, Capability::Assignments, Capability::Submissions, Capability::Announcements, Capability::Messaging, Capability::Calendar, Capability::Quizzes, Capability::Attendance, Capability::ProgressTracking],
            Preset::PrivateTutor => [Capability::Resources, Capability::Assignments, Capability::Messaging, Capability::Calendar, Capability::ProgressTracking, Capability::LessonScheduling],
            Preset::TrainingCenter => [Capability::Resources, Capability::Assignments, Capability::Submissions, Capability::Announcements, Capability::Messaging, Capability::Calendar, Capability::AdvancedAdministration, Capability::Attendance, Capability::ProgressTracking, Capability::Certificates],
            Preset::UniversityTeacher => [Capability::Resources, Capability::Assignments, Capability::Submissions, Capability::Announcements, Capability::Messaging, Capability::Calendar, Capability::Quizzes],
            Preset::GeneralSchool => [Capability::Resources, Capability::Assignments, Capability::Submissions, Capability::Announcements, Capability::Messaging, Capability::Calendar, Capability::Quizzes, Capability::Attendance, Capability::ProgressTracking],
            Preset::ClubAssociation => [Capability::Resources, Capability::Announcements, Capability::Messaging, Capability::Calendar, Capability::Attendance],
            Preset::Custom => [],
        };

        return new PresetDefinition($preset, match ($preset) {
            Preset::Ofppt => TerminologyPreset::Ofppt,
            Preset::LanguageTeacher => TerminologyPreset::LanguageSchool,
            default => TerminologyPreset::Generic,
        }, $recommendations);
    }

    /** @return list<PresetDefinition> */
    public static function all(): array
    {
        return array_map(self::get(...), Preset::cases());
    }
}
