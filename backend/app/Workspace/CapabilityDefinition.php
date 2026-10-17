<?php

namespace App\Workspace;

final readonly class CapabilityDefinition
{
    /**
     * @param  list<Capability>  $dependencies
     * @param  list<string>  $supportedContexts  Existing adapters, not promised generic workflows.
     * @param  list<string>  $contextRequirements  Resource prerequisites, not permissions or global feature dependencies.
     * @param  list<Preset>  $recommendedPresets
     */
    public function __construct(
        public Capability $id,
        public CapabilityAvailability $availability,
        public array $dependencies,
        public array $supportedContexts,
        public array $recommendedPresets,
        public string $historyPolicy,
        public array $contextRequirements = [],
    ) {}

    public function labelKey(): string
    {
        return 'workspace.capabilities.'.$this->id->value.'.label';
    }

    public function descriptionKey(): string
    {
        return 'workspace.capabilities.'.$this->id->value.'.description';
    }

    public function isAvailable(): bool
    {
        return $this->availability === CapabilityAvailability::Implemented;
    }

    public function disablePolicy(): string
    {
        return 'freeze_active_preserve_data_and_authorized_history';
    }
}
