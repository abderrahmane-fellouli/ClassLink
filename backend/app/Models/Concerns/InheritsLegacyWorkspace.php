<?php

namespace App\Models\Concerns;

use App\Workspace\LegacyWorkspaceScope;

trait InheritsLegacyWorkspace
{
    public static function bootInheritsLegacyWorkspace(): void
    {
        static::saving(function ($model) {
            $stamped = LegacyWorkspaceScope::stamp($model->getTable(), $model->getAttributes());
            if (array_key_exists('workspace_id', $stamped)) {
                $original = $model->getRawOriginal('workspace_id');
                if ($model->exists && $original !== null && (int) $original !== $stamped['workspace_id']) {
                    throw new \RuntimeException('existing_root_scope_is_immutable');
                }
                $model->setAttribute('workspace_id', $stamped['workspace_id']);
            }
        });
    }

    public function initializeInheritsLegacyWorkspace(): void
    {
        $this->makeHidden('workspace_id');
    }
}
