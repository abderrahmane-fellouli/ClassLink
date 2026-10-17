<?php

namespace App\Workspace;

final readonly class PresetDefinition
{
    /** @param list<Capability> $recommendations Metadata, never automatically persisted/enabled. */
    public function __construct(
        public Preset $id,
        public TerminologyPreset $terminology,
        public array $recommendations,
        public int $version = 1,
    ) {}

    public function labelKey(): string
    {
        return 'workspace.presets.'.$this->id->value;
    }

    /** Future recommendations must never be rendered as usable features. @return list<Capability> */
    public function availableRecommendations(): array
    {
        return array_values(array_filter($this->recommendations, fn (Capability $id) => CapabilityRegistry::isImplemented($id)));
    }

    /** @return list<Capability> */
    public function futureRecommendations(): array
    {
        return array_values(array_filter($this->recommendations, fn (Capability $id) => ! CapabilityRegistry::isImplemented($id)));
    }
}
