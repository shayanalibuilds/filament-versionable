<?php

namespace Mansoor\FilamentVersionable\Support;

class RelationRecordChange
{
    /**
     * @param  int|string  $key  the related record primary key
     * @param  array<string, mixed>|null  $old  snapshot attributes from the previous version (null when added)
     * @param  array<string, mixed>|null  $new  snapshot attributes from the current version (null when removed)
     * @param  array<string, array{old: ?string, new: ?string, html: string}>  $fields  per-field diff payload
     */
    public function __construct(
        public int|string $key,
        public ?array $old = null,
        public ?array $new = null,
        public array $fields = [],
    ) {}

    public function label(): string
    {
        $label = '#'.$this->key;

        $attributes = $this->new ?? $this->old ?? [];

        foreach (['name', 'title', 'label'] as $candidate) {
            $value = $attributes[$candidate] ?? null;

            if (is_scalar($value) && filled($value)) {
                return $label.' — '.strval($value);
            }
        }

        return $label;
    }
}
