<?php

namespace App\Workspace;

use Illuminate\Validation\ValidationException;

final class CapabilityRegistry
{
    public static function isImplemented(Capability $id): bool
    {
        return ! in_array($id, [Capability::Attendance, Capability::ProgressTracking, Capability::Certificates, Capability::LessonScheduling], true);
    }

    public static function get(Capability $id): CapabilityDefinition
    {
        $implemented = self::isImplemented($id);
        $dependencies = match ($id) {
            Capability::Submissions => [Capability::Assignments],
            Capability::GradeImport => [Capability::OfficialGrades],
            default => [],
        };
        $contexts = match ($id) {
            Capability::Attendance, Capability::ProgressTracking, Capability::Certificates, Capability::LessonScheduling => [],
            Capability::AiTools, Capability::HistoricalClasses, Capability::QuizProgress => ['historical'],
            Capability::AcademicYears, Capability::InstitutionalModules, Capability::TeachingAssignments,
            Capability::OfficialGrades, Capability::GradeImport, Capability::Delegates, Capability::Admissions,
            Capability::RosterImport, Capability::Transfers, Capability::Messaging => ['institutional'],
            Capability::AdvancedAdministration => ['platform', 'institutional'],
            default => ['historical', 'institutional'],
        };

        return new CapabilityDefinition(
            $id,
            $implemented ? CapabilityAvailability::Implemented : CapabilityAvailability::Future,
            $dependencies,
            $contexts,
            array_map(fn (PresetDefinition $p) => $p->id, array_values(array_filter(PresetRegistry::all(), fn (PresetDefinition $p) => in_array($id, $p->recommendations, true)))),
            match ($id) {
                Capability::Attendance, Capability::ProgressTracking, Capability::Certificates, Capability::LessonScheduling => 'unavailable',
                Capability::GradeImport, Capability::RosterImport => 'committed_provenance_only',
                Capability::OfficialGrades => 'own_published_results_and_existing_teacher_read',
                Capability::Messaging => 'explicit_participant_and_continuing_eligibility',
                default => 'existing_authorized_read_only',
            },
            match ($id) {
                Capability::OfficialGrades => ['institutional_offering', 'academic_year', 'current_teaching_assignment'],
                Capability::Delegates => ['official_group', 'current_enrollment', 'existing_delegate_limits'],
                Capability::TeachingAssignments => ['institutional_offering', 'approved_teacher'],
                Capability::Transfers => ['official_groups_in_same_academic_year'],
                default => [],
            },
        );
    }

    /** @return list<CapabilityDefinition> */
    public static function all(): array
    {
        return array_map(self::get(...), Capability::cases());
    }

    /** Validate a complete proposed snapshot; never silently enable prerequisites. @param list<Capability> $enabled */
    public static function validateEnabled(array $enabled): void
    {
        foreach ($enabled as $id) {
            $definition = self::get($id);
            if (! $definition->isAvailable()) {
                throw ValidationException::withMessages(['capabilities' => $id->value.' is not implemented.']);
            }
            foreach ($definition->dependencies as $dependency) {
                if (! in_array($dependency, $enabled, true)) {
                    throw ValidationException::withMessages(['capabilities' => $id->value.' requires '.$dependency->value.'.']);
                }
            }
        }
    }
}
