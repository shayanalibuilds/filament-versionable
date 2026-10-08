<?php

namespace Mansoor\FilamentVersionable\Support;

use Jfcherng\Diff\DiffHelper;
use Overtrue\LaravelVersionable\Version;

/**
 * Computes per-relation change sets between two versions by comparing
 * their stored relationship snapshots.
 */
class RelationDiff
{
    /**
     * @param  array<string, mixed>  $differOptions
     * @param  array<string, mixed>  $renderOptions
     */
    public function __construct(
        public Version $newVersion,
        public ?Version $oldVersion,
        public array $differOptions = [],
        public array $renderOptions = [],
        public bool $stripTags = false,
    ) {}

    /**
     * @return list<RelationChangeSet>
     */
    public function changes(): array
    {
        $newRelations = RelationSnapshotManager::forVersion($this->newVersion);
        $oldRelations = $this->oldVersion !== null
            ? RelationSnapshotManager::forVersion($this->oldVersion)
            : [];

        $changeSets = [];

        foreach ($newRelations + $oldRelations as $name => $_) {
            $changeSet = $this->compareRelation(
                name: $name,
                old: $oldRelations[$name] ?? null,
                new: $newRelations[$name] ?? null,
            );

            if ($changeSet->hasChanges()) {
                $changeSets[] = $changeSet;
            }
        }

        return $changeSets;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    protected function compareRelation(string $name, ?array $old, ?array $new): RelationChangeSet
    {
        $type = $new['type'] ?? $old['type'] ?? 'unknown';
        $displayOnly = (bool) ($new['display_only'] ?? $old['display_only'] ?? false);

        $changeSet = new RelationChangeSet(
            name: $name,
            type: $type,
            displayOnly: $displayOnly,
            oldState: is_array($old['records'] ?? null) ? $old['records'] : null,
            newState: is_array($new['records'] ?? null) ? $new['records'] : null,
        );

        if ($displayOnly && in_array($type, ['belongsTo', 'morphTo'], true)) {
            $this->compareSingleRecord($changeSet);

            return $changeSet;
        }

        $oldRecords = $changeSet->oldState ?? [];
        $newRecords = $changeSet->newState ?? [];

        foreach ($newRecords as $key => $newRecord) {
            if (! array_key_exists($key, $oldRecords)) {
                $changeSet->added[] = new RelationRecordChange(
                    key: $key,
                    old: null,
                    new: $newRecord,
                    fields: $this->renderRecordFields(null, $newRecord),
                );

                continue;
            }

            $oldRecord = $oldRecords[$key];

            if ($oldRecord === $newRecord) {
                $changeSet->unchanged++;

                continue;
            }

            $changeSet->updated[] = new RelationRecordChange(
                key: $key,
                old: $oldRecord,
                new: $newRecord,
                fields: $this->renderChangedFields($oldRecord, $newRecord),
            );
        }

        foreach ($oldRecords as $key => $oldRecord) {
            if (! array_key_exists($key, $newRecords)) {
                $changeSet->removed[] = new RelationRecordChange(
                    key: $key,
                    old: $oldRecord,
                    new: null,
                    fields: $this->renderRecordFields($oldRecord, null),
                );
            }
        }

        return $changeSet;
    }

    /**
     * Compare single-record relations (belongsTo / morphTo): the related
     * record itself is never restored, the change is shown for reference.
     */
    protected function compareSingleRecord(RelationChangeSet $changeSet): void
    {
        $oldRecord = $changeSet->oldState !== null ? ($changeSet->oldState[array_key_first($changeSet->oldState)] ?? null) : null;
        $newRecord = $changeSet->newState !== null ? ($changeSet->newState[array_key_first($changeSet->newState)] ?? null) : null;

        if ($oldRecord === $newRecord) {
            return;
        }

        $oldKey = $changeSet->oldState !== null ? array_key_first($changeSet->oldState) : null;
        $newKey = $changeSet->newState !== null ? array_key_first($changeSet->newState) : null;

        $changeSet->updated[] = new RelationRecordChange(
            key: $newKey ?? $oldKey ?? '?',
            old: $oldRecord,
            new: $newRecord,
            fields: $this->renderChangedFields($oldRecord ?? [], $newRecord ?? []),
        );
    }

    /**
     * Render every field of a fully added or removed record.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return array<string, array{old: ?string, new: ?string, html: string}>
     */
    protected function renderRecordFields(?array $old, ?array $new): array
    {
        $fields = [];

        foreach (array_keys($new ?? $old ?? []) as $field) {
            $oldValue = $old === null ? null : static::stringify($old, $field);
            $newValue = $new === null ? null : static::stringify($new, $field);

            $fields[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
                'html' => $this->renderFieldDiff($oldValue, $newValue),
            ];
        }

        return $fields;
    }

    /**
     * Render only the fields that changed between two record snapshots.
     *
     * @param  array<string, mixed>  $oldRecord
     * @param  array<string, mixed>  $newRecord
     * @return array<string, array{old: ?string, new: ?string, html: string}>
     */
    protected function renderChangedFields(array $oldRecord, array $newRecord): array
    {
        $fields = [];

        foreach (array_keys($oldRecord + $newRecord) as $field) {
            $oldValue = static::stringify($oldRecord, $field);
            $newValue = static::stringify($newRecord, $field);

            if ($oldValue === $newValue) {
                continue;
            }

            $fields[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
                'html' => $this->renderFieldDiff($oldValue, $newValue),
            ];
        }

        return $fields;
    }

    /**
     * Normalize a snapshot value to a diffable string, mirroring how the
     * overtrue renderer treats non-string values.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function stringify(array $data, int|string $field): ?string
    {
        if (! array_key_exists($field, $data)) {
            return null;
        }

        $value = $data[$field];

        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Render a field diff using the same renderer and options as the
     * attribute diff on the revisions page.
     */
    protected function renderFieldDiff(?string $old, ?string $new): string
    {
        return str_replace(
            '\n No newline at end of file',
            '',
            DiffHelper::calculate(
                $old ?? '',
                $new ?? '',
                'SideBySide',
                $this->differOptions ?: ['fullContextIfIdentical' => true],
                $this->renderOptions ?: ['lineNumbers' => false, 'showHeader' => false, 'detailLevel' => 'word', 'spacesToNbsp' => false],
            ),
        );
    }
}
