<?php

namespace Mansoor\FilamentVersionable\Support;

use Filament\Resources\Events\RecordSaved;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Overtrue\LaravelVersionable\Version;
use Throwable;

/**
 * Captures, stores and restores relationship snapshots for Versionable models.
 *
 * Snapshots are stored in the nullable `relations` JSON column of the
 * `versions` table (publish the plugin migration). Models opt in by using
 * the `HasVersionableRelations` concern and listing relation names.
 *
 * Snapshot format (per relation):
 *
 *   [
 *       'comments' => [
 *           'type'         => 'hasMany',
 *           'related'      => App\Models\Comment::class,
 *           'display_only' => false,
 *           'records'      => [
 *               '5' => ['author' => 'Alice', 'body' => '...'],
 *           ],
 *       ],
 *   ]
 */
class RelationSnapshotManager
{
    /**
     * Versions created very recently, keyed by "{morph_type}:{id}".
     *
     * @var array<string, array{version_id: int|string, ts: float}>
     */
    protected static array $recentVersions = [];

    /**
     * Cached results of the `relations` column existence check, keyed by table.
     *
     * @var array<string, bool>
     */
    protected static array $relationsColumnCache = [];

    /**
     * Register the global event hooks (version creation + Filament saves).
     */
    public static function registerEventHooks(): void
    {
        Version::creating(function (Version $version): void {
            static::handleVersionCreating($version);
        });

        // The primary key is only available once the row was inserted.
        Version::created(function (Version $version): void {
            static::rememberRecentVersion($version);
        });

        // Filament dispatches RecordSaved AFTER the form's relationships have
        // been persisted (both on create and edit pages), which makes it the
        // reliable point to record / refresh the relationship state.
        //
        // Filament fires it class-style: Event::dispatch(RecordSaved::class,
        // ['record' => ..., 'data' => ..., 'page' => ...]) — listeners then
        // receive the payload as individual arguments. Some userland code
        // dispatches an event instance instead, so both shapes are handled.
        Event::listen(RecordSaved::class, function (...$arguments): void {
            $first = $arguments[0] ?? null;

            if ($first instanceof RecordSaved) {
                static::handleRecordSaved($first->getRecord());

                return;
            }

            if ($first instanceof Model) {
                static::handleRecordSaved($first);
            }
        });
    }

    /**
     * Whether the model opted into relationship versioning.
     */
    public static function usesVersionableRelations(Model $model): bool
    {
        return method_exists($model, 'usesVersionableRelations')
            && $model->usesVersionableRelations();
    }

    /**
     * The relation names configured for versioning.
     *
     * @return list<string>
     */
    public static function versionableRelations(Model $model): array
    {
        if (! method_exists($model, 'getVersionableRelations')) {
            return [];
        }

        return $model->getVersionableRelations();
    }

    /**
     * Capture the current state of all versionable relations of a model.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function captureSnapshot(Model $record): array
    {
        $snapshot = [];

        foreach (static::versionableRelations($record) as $name) {
            if (! method_exists($record, $name)) {
                continue;
            }

            $entry = static::snapshotRelation($record, $name);

            if ($entry !== null) {
                $snapshot[$name] = $entry;
            }
        }

        return $snapshot;
    }

    /**
     * Store a snapshot on a persisted version row (no-op when the column
     * is missing or the data is unchanged).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function storeSnapshot(Version $version, array $snapshot): void
    {
        if (! static::hasRelationsColumn($version)) {
            return;
        }

        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        if ($version->getAttribute('relations') === $encoded) {
            return;
        }

        $version->forceFill(['relations' => $encoded])->save();
    }

    /**
     * Apply a snapshot to a version that is not persisted yet (inside the
     * `creating` event): sets the raw attribute so it is written with the
     * INSERT instead of triggering a nested save.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected static function applySnapshot(Version $version, array $snapshot): void
    {
        if (! static::hasRelationsColumn($version)) {
            return;
        }

        $version->forceFill(['relations' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Decode the relation snapshot stored on a version.
     *
     * @return array<string, mixed>
     */
    public static function forVersion(Version $version): array
    {
        $raw = $version->getAttribute('relations');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Restore relationship state from a snapshot. Only relations present in
     * the snapshot are touched, so versions recorded before this feature
     * was enabled never lose data.
     *
     * @param  array<string|int, mixed>  $snapshot
     */
    public static function restore(Model $record, array $snapshot): void
    {
        foreach ($snapshot as $name => $data) {
            if (! is_string($name) || ! is_array($data) || ! method_exists($record, $name)) {
                continue;
            }

            $type = $data['type'] ?? null;
            $records = $data['records'] ?? null;

            if (! is_string($type) || ! is_array($records)) {
                continue;
            }

            match (true) {
                in_array($type, ['hasMany', 'morphMany', 'hasOne', 'morphOne'], true) => static::restoreChildren($record, $name, $records),
                in_array($type, ['belongsToMany', 'morphToMany'], true) => static::restorePivots($record, $name, $records),
                default => null, // display-only relations are never restored
            };
        }
    }

    /**
     * Handle a version being created: attach a fresh relations snapshot
     * and remember it so a following Filament save can refresh it.
     */
    protected static function handleVersionCreating(Version $version): void
    {
        $record = static::resolveVersionable($version);

        if ($record === null || ! static::usesVersionableRelations($record)) {
            return;
        }

        static::applySnapshot($version, static::captureSnapshot($record));
    }

    /**
     * Handle a Filament RecordSaved event for a versionable model.
     *
     * Covers the case where ONLY relationships changed (the parent model
     * is not dirty, so overtrue's attribute watcher never creates a
     * version): a new version is created to record the relation change.
     */
    protected static function handleRecordSaved(Model $record): void
    {
        if (! static::usesVersionableRelations($record)) {
            return;
        }

        $snapshot = static::captureSnapshot($record);

        // A version was just created during this save cycle (attribute
        // change or initial version): refresh its snapshot, because on the
        // create flow it was captured before Filament saved relationships.
        $recent = static::recentVersionFor($record);

        if ($recent !== null) {
            static::storeSnapshot($recent, $snapshot);

            static::forgetRecentVersion($record);

            return;
        }

        // Nothing changed at all: avoid creating noise versions.
        $latest = method_exists($record, 'latestVersion')
            ? $record->latestVersion()->first()
            : null;

        if ($latest !== null && static::encoded($snapshot) === static::encoded(static::forVersion($latest))) {
            return;
        }

        // Only relationships changed: create a version for this state.
        Version::createForModel($record);
    }

    /**
     * Snapshot a single relation of a model.
     *
     * @return array{type: string, related: string, display_only: bool, records: array<string, array<string, mixed>>}|null
     */
    protected static function snapshotRelation(Model $record, string $name): ?array
    {
        try {
            $relation = $record->{$name}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof Relation) {
            return null;
        }

        $type = match (true) {
            $relation instanceof MorphToMany => 'morphToMany',
            $relation instanceof BelongsToMany => 'belongsToMany',
            $relation instanceof MorphTo => 'morphTo',
            $relation instanceof BelongsTo => 'belongsTo',
            $relation instanceof MorphOne => 'morphOne',
            $relation instanceof MorphMany => 'morphMany',
            $relation instanceof HasOne => 'hasOne',
            $relation instanceof HasMany => 'hasMany',
            $relation instanceof HasOneThrough => 'hasOneThrough',
            $relation instanceof HasManyThrough => 'hasManyThrough',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $relatedInstance = $relation->getRelated();

        // Execute the relation query instead of relying on `$record->{$name}`
        // property access, which would return a stale eager-cached result
        // when the same model instance is saved multiple times.
        $isSingleRecord = in_array($type, ['belongsTo', 'morphTo', 'hasOne', 'morphOne'], true);

        $models = $isSingleRecord
            ? collect([$relation->first()])->filter()
            : $relation->get();

        $records = [];

        foreach ($models as $related) {
            $key = $related->getAttribute($related->getKeyName());

            if ($key === null) {
                continue;
            }

            $records[$key] = static::serializeRelated($relation, $related);
        }

        return [
            'type' => $type,
            'related' => $relatedInstance::class,
            'display_only' => in_array($type, ['belongsTo', 'morphTo', 'hasManyThrough', 'hasOneThrough'], true),
            'records' => $records,
        ];
    }

    /**
     * Serialize a related model for the snapshot (casts applied, hidden and
     * timestamp attributes excluded, pivot columns preserved).
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @return array<string, mixed>
     */
    protected static function serializeRelated(Relation $relation, Model $related): array
    {
        $attributes = $related->setAppends([])->attributesToArray();

        unset(
            $attributes[$related->getCreatedAtColumn()],
            $attributes[$related->getUpdatedAtColumn()],
        );

        if (method_exists($related, 'getDeletedAtColumn')) {
            unset($attributes[$related->getDeletedAtColumn()]);
        }

        if ($relation instanceof MorphToMany) {
            $pivotAttributes = $related->pivot?->attributesToArray() ?? [];

            unset(
                $pivotAttributes[$relation->getForeignPivotKeyName()],
                $pivotAttributes[$relation->getRelatedPivotKeyName()],
                $pivotAttributes[$relation->getMorphType()],
            );

            $attributes['_pivot'] = Arr::except($pivotAttributes, ['created_at', 'updated_at']);
        } elseif ($relation instanceof BelongsToMany) {
            $pivotAttributes = $related->pivot?->attributesToArray() ?? [];

            unset(
                $pivotAttributes[$relation->getForeignPivotKeyName()],
                $pivotAttributes[$relation->getRelatedPivotKeyName()],
            );

            $attributes['_pivot'] = Arr::except($pivotAttributes, ['created_at', 'updated_at']);
        }

        return $attributes;
    }

    /**
     * Restore list-style / single-child relations (HasMany, MorphMany,
     * HasOne, MorphOne): children are upserted by primary key (including
     * re-claiming children that were moved or soft-deleted), and children
     * that are not part of the snapshot are removed.
     *
     * @param  array<string, mixed>  $records
     */
    protected static function restoreChildren(Model $record, string $name, array $records): void
    {
        $relation = $record->{$name}();

        if (! $relation instanceof Relation) {
            return;
        }

        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($related), true);

        foreach ($records as $key => $attributes) {
            $attributes = Arr::except(is_array($attributes) ? $attributes : [], ['_pivot']);

            // The SoftDeletes trait registers `withTrashed` as a builder
            // scope macro. Removing the scope directly achieves the same
            // result (trashed rows included) with a real builder method.
            $query = $related::query();

            if ($usesSoftDeletes) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            $existing = $query->find($key);

            if ($existing !== null) {
                if ($usesSoftDeletes
                    && method_exists($existing, 'restore')
                    && method_exists($existing, 'trashed')
                    && $existing->trashed()
                ) {
                    $existing->restore();
                }

                Model::unguarded(fn (): bool => $existing->fill($attributes)->save());

                continue;
            }

            $created = new $related;

            Model::unguarded(function () use ($created, $key, $keyName, $attributes): void {
                $created->fill($attributes + [$keyName => $key])->save();
            });
        }

        $snapshotKeys = array_map(strval(...), array_keys($records));

        $relation->get()->each(function (Model $child) use ($snapshotKeys, $keyName, $usesSoftDeletes): void {
            if (in_array(strval($child->getAttribute($keyName)), $snapshotKeys, true)) {
                return;
            }

            // Children outside the snapshot are removed. Soft-deletable
            // children are soft-deleted; already-trashed children are left
            // alone to avoid destroying data.
            if ($usesSoftDeletes && method_exists($child, 'trashed')) {
                if (! $child->trashed()) {
                    $child->delete();
                }

                return;
            }

            $child->delete();
        });
    }

    /**
     * Restore pivot relations (BelongsToMany, MorphToMany) by syncing the
     * pivot table. Related records themselves are shared master data and
     * are never created or modified.
     *
     * @param  array<string, mixed>  $records
     */
    protected static function restorePivots(Model $record, string $name, array $records): void
    {
        $relation = $record->{$name}();

        if (! $relation instanceof BelongsToMany) {
            return;
        }

        $related = $relation->getRelated();
        $relatedKey = $related->getKeyName();

        $syncMap = [];

        foreach ($records as $key => $attributes) {
            $pivotData = Arr::get((array) $attributes, '_pivot', []);

            $syncMap[$key] = is_array($pivotData) ? $pivotData : [];
        }

        if ($syncMap === []) {
            $relation->sync([]);

            return;
        }

        $existingKeys = $related::query()
            ->whereIn($relatedKey, array_keys($syncMap))
            ->pluck($relatedKey)
            ->all();

        $relation->sync(Arr::only($syncMap, $existingKeys));
    }

    /**
     * Resolve the model a version belongs to from its morph columns.
     */
    protected static function resolveVersionable(Version $version): ?Model
    {
        $type = $version->getAttribute('versionable_type');
        $id = $version->getAttribute('versionable_id');

        if (! is_string($type) || $type === '' || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($id);
    }

    protected static function rememberRecentVersion(Version $version): void
    {
        $type = $version->getAttribute('versionable_type');
        $id = $version->getAttribute('versionable_id');

        if (! is_string($type) || $type === '' || $id === null) {
            return;
        }

        static::$recentVersions["{$type}:{$id}"] = [
            'version_id' => $version->getKey(),
            'ts' => microtime(true),
        ];
    }

    protected static function forgetRecentVersion(Model $record): void
    {
        unset(static::$recentVersions[$record->getMorphClass().':'.$record->getKey()]);
    }

    /**
     * The version created moments ago for this model, if it is still the
     * latest one. Guarded by a short time window so saves from earlier
     * cycles cannot be mistaken for the current one.
     */
    protected static function recentVersionFor(Model $record): ?Version
    {
        $entry = static::$recentVersions[$record->getMorphClass().':'.$record->getKey()] ?? null;

        if ($entry === null || (microtime(true) - $entry['ts']) > 5) {
            return null;
        }

        if (! method_exists($record, 'latestVersion')) {
            return null;
        }

        $latest = $record->latestVersion()->first();

        if ($latest === null || $latest->getKey() != $entry['version_id']) {
            return null;
        }

        return $latest;
    }

    protected static function hasRelationsColumn(Version $version): bool
    {
        $table = $version->getTable();

        if (array_key_exists($table, static::$relationsColumnCache)) {
            return static::$relationsColumnCache[$table];
        }

        try {
            return static::$relationsColumnCache[$table] = Schema::hasTable($table)
                && Schema::hasColumn($table, 'relations');
        } catch (Throwable) {
            return static::$relationsColumnCache[$table] = false;
        }
    }

    /**
     * Stable string representation used to compare two snapshots.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected static function encoded(array $snapshot): string
    {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Test helper: forget all cached runtime state.
     */
    public static function flushRuntimeState(): void
    {
        static::$recentVersions = [];
        static::$relationsColumnCache = [];
    }
}
