<?php

namespace Tests\Unit;

use App\Workspace\Capability;
use App\Workspace\CapabilityAvailability;
use App\Workspace\CapabilityRegistry;
use App\Workspace\Preset;
use App\Workspace\PresetRegistry;
use App\Workspace\TerminologyPreset;
use PHPUnit\Framework\TestCase;

class WorkspaceRegistryTest extends TestCase
{
    public function test_all_capabilities_have_stable_metadata_and_future_features_are_unavailable(): void
    {
        $definitions = CapabilityRegistry::all();
        $this->assertCount(count(Capability::cases()), $definitions);
        $future = [];
        foreach ($definitions as $definition) {
            $this->assertSame('workspace.capabilities.'.$definition->id->value.'.label', $definition->labelKey());
            $this->assertStringEndsWith('.description', $definition->descriptionKey());
            $this->assertSame('freeze_active_preserve_data_and_authorized_history', $definition->disablePolicy());
            foreach ($definition->dependencies as $dependency) {
                $this->assertInstanceOf(Capability::class, $dependency);
            }
            if ($definition->availability === CapabilityAvailability::Future) {
                $future[] = $definition->id->value;
                $this->assertFalse($definition->isAvailable());
                $this->assertSame([], $definition->supportedContexts);
                $this->assertSame('unavailable', $definition->historyPolicy);
            } else {
                $this->assertNotEmpty($definition->supportedContexts);
            }
        }
        $this->assertSame(['attendance', 'progress_tracking', 'certificates', 'lesson_scheduling'], $future);
        $this->assertSame(['historical'], CapabilityRegistry::get(Capability::AiTools)->supportedContexts);
        $this->assertSame(['historical'], CapabilityRegistry::get(Capability::QuizProgress)->supportedContexts);
    }

    public function test_presets_are_recommendations_with_separate_future_items_not_feature_editions(): void
    {
        $this->assertCount(8, PresetRegistry::all());
        $ofppt = PresetRegistry::get(Preset::Ofppt);
        $this->assertSame(TerminologyPreset::Ofppt, $ofppt->terminology);
        $this->assertSame([], $ofppt->futureRecommendations());
        $this->assertSame(array_values(array_filter(Capability::cases(), CapabilityRegistry::isImplemented(...))), $ofppt->availableRecommendations());
        CapabilityRegistry::validateEnabled($ofppt->availableRecommendations());
        foreach (PresetRegistry::all() as $preset) {
            $this->assertSame(1, $preset->version);
            $this->assertSame('workspace.presets.'.$preset->id->value, $preset->labelKey());
            $this->assertCount(count(array_unique(array_map(fn ($c) => $c->value, $preset->recommendations))), $preset->recommendations);
            CapabilityRegistry::validateEnabled($preset->availableRecommendations());
        }
        $language = PresetRegistry::get(Preset::LanguageTeacher);
        $this->assertNotContains(Capability::OfficialGrades, $language->recommendations);
        $this->assertNotContains(Capability::Delegates, $language->recommendations);
        $this->assertContains(Capability::ProgressTracking, $language->futureRecommendations());
        $tutor = PresetRegistry::get(Preset::PrivateTutor);
        $this->assertNotContains(Capability::AcademicYears, $tutor->recommendations);
        $this->assertContains(Capability::LessonScheduling, $tutor->futureRecommendations());
        $this->assertSame([], PresetRegistry::get(Preset::Custom)->recommendations);
    }

    public function test_terminology_keeps_evaluation_separate_from_official_grades_and_fr_en_forms(): void
    {
        $generic = TerminologyPreset::Generic->terms('fr');
        $ofppt = TerminologyPreset::Ofppt->terms('fr');
        $language = TerminologyPreset::LanguageSchool->terms('en');
        $this->assertSame('Élève', $generic['student']['singular']);
        $this->assertSame('Stagiaires', $ofppt['student']['plural']);
        $this->assertSame('Formateur', $ofppt['teacher']['singular']);
        $this->assertSame('Module', $ofppt['course']['singular']);
        $this->assertSame('Evaluation', $language['evaluation']['singular']);
        $this->assertSame('Grade', $language['official_grade']['singular']);
        $this->assertSame(array_keys($generic), array_keys($language));
        $this->expectException(\InvalidArgumentException::class);
        TerminologyPreset::Generic->terms('unsupported');
    }
}
