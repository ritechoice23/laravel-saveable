<?php

namespace Ritechoice23\Saveable\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;
use Ritechoice23\Saveable\Models\Collection;
use Ritechoice23\Saveable\Models\Save;

trait HasSaves
{
    /**
     * Boot the HasSaves trait.
     */
    public static function bootHasSaves(): void
    {
        static::deleting(function ($model) {
            // Delete all saves where this model is the saver
            $model->savedRecords()->delete();

            // Delete all collections owned by this model
            $model->collections()->get()->each->delete();
        });
    }

    /**
     * Get all Save pivot records (use when you need metadata or order).
     */
    public function savedRecords(): MorphMany
    {
        return $this->morphMany(Save::class, 'saver')->ordered();
    }

    /**
     * Get all collections owned by this model.
     */
    public function collections(): MorphMany
    {
        return $this->morphMany(Collection::class, 'owner');
    }

    /**
     * Get only root (top-level) collections.
     */
    public function rootCollections()
    {
        return $this->collections()->whereNull('parent_id');
    }

    /**
     * Get actual saved models (not Save records).
     *
     * Best for single-type saved items. For mixed types, use savedItemsGrouped().
     */
    public function savedItems(?string $type = null): Builder
    {
        return $this->buildSavedItemsQuery($type);
    }

    /**
     * Get saved items grouped by model type (perfect for mixed saveable types).
     *
     * Note: This method returns a Collection of models grouped by type, not a Builder.
     * If you need to chain query methods, use savedItems($type) for a specific type instead.
     * For mixed types with additional constraints, you'll need to query each type separately.
     *
     * @return SupportCollection<string, SupportCollection>
     */
    public function savedItemsGrouped(): SupportCollection
    {
        $saveRecords = $this->savedRecords()
            ->select('saveable_type', 'saveable_id')
            ->get()
            ->groupBy('saveable_type');

        $grouped = collect();

        foreach ($saveRecords as $type => $records) {
            // Convert morph alias to class name if needed
            $modelClass = $this->getMorphClassFromType($type);

            if (! $modelClass) {
                continue;
            }

            $ids = $records->pluck('saveable_id')->unique()->values();
            $models = $modelClass::whereIn('id', $ids)->get();

            // Key by the morph alias (what's stored in database), not the class name
            $grouped->put($type, $models);
        }

        return $grouped;
    }

    /**
     * Save an item (with optional collection and metadata).
     */
    public function saveItem(Model $model, ?Collection $collection = null, array $metadata = []): bool
    {
        if ($this->hasSavedItem($model)) {
            return false;
        }

        $orderColumn = 0;

        if (config('saveable.auto_ordering', true)) {
            // Get the next order number for this scope (user + collection)
            $orderColumn = Save::where('saver_type', $this->getMorphClass())
                ->where('saver_id', $this->getKey())
                ->where('collection_id', $collection?->id)
                ->max('order_column') + 1;
        }

        $save = $this->savedRecords()->create([
            'saveable_type' => $model->getMorphClass(),
            'saveable_id' => $model->getKey(),
            'collection_id' => $collection?->id,
            'metadata' => $metadata,
            'order_column' => $orderColumn,
        ]);

        return $save !== null;
    }

    /**
     * Unsave an item.
     */
    public function unsaveItem(Model $model): bool
    {
        return $this->savedRecords()
            ->where('saveable_type', $model->getMorphClass())
            ->where('saveable_id', $model->getKey())
            ->delete() > 0;
    }

    /**
     * Toggle save status.
     */
    public function toggleSaveItem(Model $model, ?Collection $collection = null, array $metadata = []): bool
    {
        if ($this->hasSavedItem($model)) {
            $this->unsaveItem($model);

            return false;
        }

        $this->saveItem($model, $collection, $metadata);

        return true;
    }

    /**
     * Check if this model has saved another model.
     */
    public function hasSavedItem(Model $model): bool
    {
        return $this->savedRecords()
            ->where('saveable_type', $model->getMorphClass())
            ->where('saveable_id', $model->getKey())
            ->exists();
    }

    /**
     * Get the save record for a model.
     */
    public function getSavedRecord(Model $model): ?Save
    {
        return $this->savedRecords()
            ->where('saveable_type', $model->getMorphClass())
            ->where('saveable_id', $model->getKey())
            ->first();
    }

    /**
     * Move a saved item to a different collection.
     */
    public function moveSavedItem(Model $model, ?Collection $collection): bool
    {
        $save = $this->getSavedRecord($model);

        if (! $save) {
            return false;
        }

        $save->update(['collection_id' => $collection?->id]);

        return true;
    }

    /**
     * Update metadata on an existing save.
     */
    public function updateSavedItemMetadata(Model $model, array $metadata): bool
    {
        $save = $this->getSavedRecord($model);

        if (! $save) {
            return false;
        }

        $save->update(['metadata' => array_merge($save->metadata ?? [], $metadata)]);

        return true;
    }

    /**
     * Get all saved items of a specific type.
     */
    public function savedItemsOfType(string $type): Builder
    {
        return $this->buildSavedItemsQuery($type);
    }

    /**
     * Get all unsorted saves (not in any collection).
     */
    public function unsortedSavedRecords(): SupportCollection
    {
        return $this->savedRecords()->whereNull('collection_id')->get();
    }

    /**
     * Get all unsorted items of a specific type.
     */
    public function unsortedSavedItems(?string $type = null): Builder
    {
        $savesTable = config('saveable.saves_table', 'saves');

        if ($type !== null) {
            return $this->buildUnsortedSavedItemsQuery($type, $savesTable);
        }

        // Get distinct types that have unsorted saves
        $distinctTypes = $this->savedRecords()
            ->whereNull('collection_id')
            ->select('saveable_type')
            ->distinct()
            ->pluck('saveable_type')
            ->map(fn ($t) => $this->getMorphClassFromType($t))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($distinctTypes) === 0) {
            // Return empty query - just use newQuery on current model
            return $this->newQuery()->whereRaw('1 = 0');
        }

        if (count($distinctTypes) === 1) {
            return $this->buildUnsortedSavedItemsQuery($distinctTypes[0], $savesTable);
        }

        return $this->buildMixedTypesUnsortedQuery($distinctTypes, $savesTable);
    }

    /**
     * Count saved items (optionally filtered by type).
     */
    public function savedItemsCount(?string $type = null): int
    {
        $query = $this->savedRecords();

        if ($type !== null) {
            // Convert class name to morph alias if needed
            if (class_exists($type)) {
                $model = new $type;
                $type = $model->getMorphClass();
            }
            $query->where('saveable_type', $type);
        }

        return $query->count();
    }

    /**
     * Scope: Models that saved a specific model.
     */
    public function scopeWhereSavedItem(Builder $query, Model $model): Builder
    {
        return $query->whereHas('savedRecords', function ($q) use ($model) {
            $q->where('saveable_type', $model->getMorphClass())
                ->where('saveable_id', $model->getKey());
        });
    }

    /**
     * Convert morph alias to actual class name if morphMap is used.
     * Works with both morphMap aliases and full class names.
     */
    protected function getMorphClassFromType(string $type): ?string
    {
        // If it's already a valid class, return it
        if (class_exists($type)) {
            return $type;
        }

        // Try to get the class from morph map
        $morphedModel = Relation::getMorphedModel($type);

        if ($morphedModel !== null && class_exists($morphedModel)) {
            return $morphedModel;
        }

        return null;
    }

    protected function buildSavedItemsQuery(?string $type = null): Builder
    {
        $savesTable = config('saveable.saves_table', 'saves');

        if ($type !== null) {
            return $this->buildSingleTypeSavedItemsQuery($type, $savesTable);
        }

        // Get distinct types
        $distinctTypes = $this->savedRecords()
            ->select('saveable_type')
            ->distinct()
            ->pluck('saveable_type')
            ->map(fn ($t) => $this->getMorphClassFromType($t))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($distinctTypes) === 0) {
            // Return empty query - just use newQuery on current model
            return $this->newQuery()->whereRaw('1 = 0');
        }

        if (count($distinctTypes) === 1) {
            return $this->buildSingleTypeSavedItemsQuery($distinctTypes[0], $savesTable);
        }

        return $this->buildMixedTypesSavedItemsQuery($distinctTypes, $savesTable);
    }

    protected function buildSingleTypeSavedItemsQuery(string $type, string $savesTable): Builder
    {
        // Convert morph alias to class name if needed
        $modelClass = $this->getMorphClassFromType($type);

        if (! $modelClass) {
            return $this->newQuery()->whereRaw('1 = 0');
        }

        $model = new $modelClass;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $morphClass = $model->getMorphClass(); // Get the morph alias for database query

        return $model->newQuery()
            ->select("{$table}.*")
            ->join($savesTable, function ($join) use ($table, $keyName, $savesTable, $morphClass) {
                $join->on("{$savesTable}.saveable_id", '=', "{$table}.{$keyName}")
                    ->where("{$savesTable}.saveable_type", '=', $morphClass);
            })
            ->where("{$savesTable}.saver_type", $this->getMorphClass())
            ->where("{$savesTable}.saver_id", $this->getKey())
            ->orderBy("{$savesTable}.order_column", 'asc')
            ->orderBy("{$savesTable}.created_at", 'desc');
    }

    protected function buildMixedTypesSavedItemsQuery(array $types, string $savesTable): Builder
    {
        $firstModel = new $types[0];
        $builder = $firstModel->newQuery();
        $self = $this;

        $builder->macro('get', function ($columns = ['*']) use ($types, $savesTable, $self) {
            $allSavedItems = collect();

            foreach ($types as $type) {
                // Convert morph alias to class name if needed
                $modelClass = $self->getMorphClassFromType($type);

                if (! $modelClass) {
                    continue;
                }

                $results = $self->buildSingleTypeSavedItemsQuery($type, $savesTable)->get($columns);
                $allSavedItems = $allSavedItems->merge($results);
            }

            return $allSavedItems->sortBy(function ($item) use ($self) {
                $record = $self->savedRecords()
                    ->where('saveable_type', $item->getMorphClass())
                    ->where('saveable_id', $item->getKey())
                    ->first();

                return $record ? [$record->order_column, $record->created_at->timestamp * -1] : [0, 0];
            })->values();
        });

        return $builder->whereRaw('1 = 1');
    }

    protected function buildUnsortedSavedItemsQuery(string $type, string $savesTable): Builder
    {
        // Convert morph alias to class name if needed
        $modelClass = $this->getMorphClassFromType($type);

        if (! $modelClass) {
            return $this->newQuery()->whereRaw('1 = 0');
        }

        $model = new $modelClass;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $morphClass = $model->getMorphClass(); // Get the morph alias for database query

        return $model->newQuery()
            ->select("{$table}.*")
            ->join($savesTable, function ($join) use ($table, $keyName, $savesTable, $morphClass) {
                $join->on("{$savesTable}.saveable_id", '=', "{$table}.{$keyName}")
                    ->where("{$savesTable}.saveable_type", '=', $morphClass);
            })
            ->where("{$savesTable}.saver_type", $this->getMorphClass())
            ->where("{$savesTable}.saver_id", $this->getKey())
            ->whereNull("{$savesTable}.collection_id")
            ->orderBy("{$savesTable}.order_column", 'asc')
            ->orderBy("{$savesTable}.created_at", 'desc');
    }

    protected function buildMixedTypesUnsortedQuery(array $types, string $savesTable): Builder
    {
        $firstModel = new $types[0];
        $builder = $firstModel->newQuery();
        $self = $this;

        $builder->macro('get', function ($columns = ['*']) use ($types, $savesTable, $self) {
            $allSavedItems = collect();

            foreach ($types as $type) {
                // Convert morph alias to class name if needed
                $modelClass = $self->getMorphClassFromType($type);

                if (! $modelClass) {
                    continue;
                }

                $results = $self->buildUnsortedSavedItemsQuery($type, $savesTable)->get($columns);
                $allSavedItems = $allSavedItems->merge($results);
            }

            return $allSavedItems->sortBy(function ($item) use ($self) {
                $record = $self->savedRecords()
                    ->whereNull('collection_id')
                    ->where('saveable_type', $item->getMorphClass())
                    ->where('saveable_id', $item->getKey())
                    ->first();

                return $record ? [$record->order_column, $record->created_at->timestamp * -1] : [0, 0];
            })->values();
        });

        return $builder->whereRaw('1 = 1');
    }
}
