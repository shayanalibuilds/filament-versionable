<?php

namespace Mansoor\FilamentVersionable\Concerns;

/**
 * Opt-in concern that enables relationship versioning for a Versionable model.
 *
 * Declare the relationships you want versioned:
 *
 *   use Mansoor\FilamentVersionable\Concerns\HasVersionableRelations;
 *
 *   protected array $versionableRelations = ['comments', 'tags'];
 *
 * Every created version stores a snapshot of these relationships, and
 * restoring a version automatically restores the relationship state.
 */
trait HasVersionableRelations
{
    public function usesVersionableRelations(): bool
    {
        return filled($this->getVersionableRelations());
    }

    /**
     * @return list<string>
     */
    public function getVersionableRelations(): array
    {
        if (! property_exists($this, 'versionableRelations')) {
            return [];
        }

        return array_values(array_filter(
            (array) $this->versionableRelations,
            fn ($name): bool => is_string($name) && filled($name)
        ));
    }
}
