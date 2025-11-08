<?php

namespace Ritechoice23\Saveable\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Ritechoice23\Saveable\Models\Save;

trait IsSaveable
{
    /**
     * Boot the IsSaveable trait.
     */
    public static function bootIsSaveable(): void
    {
        static::deleting(function ($model) {
            // Delete all saves where this model is the saveable
            $model->saveRecords()->delete();
        });
    }

    /**
     * Get all Save pivot records (use when you need metadata).
     */
    public function saveRecords(): MorphMany
    {
        return $this->morphMany(Save::class, 'saveable');
    }

    /**
     * Get actual saver models (not Save records).
     */
    public function savers(?string $type = null): Builder
    {
        return $this->buildSaversQuery($type);
    }

    /**
     * Get savers grouped by model type.
     *
     * @return Collection<string, Collection>
     */
    public function saversGrouped(): Collection
    {
        $saveRecords = $this->saveRecords()
            ->select('saver_type', 'saver_id')
            ->get()
            ->groupBy('saver_type');

        $grouped = collect();

        foreach ($saveRecords as $type => $records) {
            // Convert morph alias to class name if needed
            $modelClass = $this->getMorphClassFromType($type);

            if (!$modelClass) {
                continue;
            }

            $ids = $records->pluck('saver_id')->unique()->values();
            $models = $modelClass::whereIn('id', $ids)->get();

            // Key by the morph alias (what's stored in database), not the class name
            $grouped->put($type, $models);
        }

        return $grouped;
    }

    /**
     * Get the total number of times this was saved.
     */
    public function timesSaved(): int
    {
        return $this->saveRecords()->count();
    }

    /**
     * Check if a model has saved this.
     */
    public function isSavedBy(Model $saver): bool
    {
        return $this->saveRecords()
            ->where('saver_type', $saver->getMorphClass())
            ->where('saver_id', $saver->getKey())
            ->exists();
    }

    /**
     * Get a model's save record for this.
     */
    public function savedRecordBy(Model $saver): ?Save
    {
        return $this->saveRecords()
            ->where('saver_type', $saver->getMorphClass())
            ->where('saver_id', $saver->getKey())
            ->first();
    }

    /**
     * Remove a specific model's save.
     */
    public function removeSavedBy(Model $saver): bool
    {
        return $this->saveRecords()
            ->where('saver_type', $saver->getMorphClass())
            ->where('saver_id', $saver->getKey())
            ->delete() > 0;
    }

    /**
     * Count savers (optionally filtered by type).
     */
    public function saversCount(?string $type = null): int
    {
        $query = $this->saveRecords();

        if ($type !== null) {
            // Convert class name to morph alias if needed
            if (class_exists($type)) {
                $model = new $type;
                $type = $model->getMorphClass();
            }
            $query->where('saver_type', $type);
        }

        return $query->count();
    }

    /**
     * Scope: Eager load save count.
     */
    public function scopeWithSavesCount($query)
    {
        return $query->withCount('saveRecords as saves_count');
    }

    /**
     * Scope: Order by most saved.
     */
    public function scopeMostSaved($query, int $limit = 10)
    {
        return $query->withCount('saveRecords as saves_count')
            ->orderByDesc('saves_count')
            ->limit($limit);
    }

    /**
     * Scope: Add save status for a specific saver.
     */
    public function scopeWithSaveStatus($query, Model $saver)
    {
        return $query->addSelect([
            'is_saved' => Save::selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')
                ->whereColumn('saveable_id', $query->getModel()->getTable() . '.id')
                ->where('saveable_type', $query->getModel()->getMorphClass())
                ->where('saver_type', $saver->getMorphClass())
                ->where('saver_id', $saver->getKey()),
            'save_metadata' => Save::select('metadata')
                ->whereColumn('saveable_id', $query->getModel()->getTable() . '.id')
                ->where('saveable_type', $query->getModel()->getMorphClass())
                ->where('saver_type', $saver->getMorphClass())
                ->where('saver_id', $saver->getKey())
                ->limit(1),
        ]);
    }

    /**
     * Scope: Filter by saved by a specific saver.
     */
    public function scopeWhereSavedBy(Builder $query, Model $saver): Builder
    {
        return $query->whereHas('saveRecords', function ($q) use ($saver) {
            $q->where('saver_type', $saver->getMorphClass())
                ->where('saver_id', $saver->getKey());
        });
    }

    protected function buildSaversQuery(?string $type = null): Builder
    {
        $savesTable = config('saveable.saves_table', 'saves');

        if ($type !== null) {
            return $this->buildSingleTypeSaversQuery($type, $savesTable);
        }

        // Get distinct types (convert morph aliases to class names)
        $distinctTypes = $this->saveRecords()
            ->select('saver_type')
            ->distinct()
            ->pluck('saver_type')
            ->map(fn($t) => $this->getMorphClassFromType($t))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($distinctTypes) === 0) {
            // Return empty query - just use newQuery on current model
            return $this->newQuery()->whereRaw('1 = 0');
        }

        if (count($distinctTypes) === 1) {
            return $this->buildSingleTypeSaversQuery($distinctTypes[0], $savesTable);
        }

        return $this->buildMixedTypesSaversQuery($distinctTypes, $savesTable);
    }

    protected function buildSingleTypeSaversQuery(string $type, string $savesTable): Builder
    {
        // Convert morph alias to class name if needed
        $modelClass = $this->getMorphClassFromType($type);

        if (!$modelClass) {
            return $this->newQuery()->whereRaw('1 = 0');
        }

        $model = new $modelClass;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $morphClass = $model->getMorphClass(); // Get the morph alias for database query

        return $model->newQuery()
            ->select("{$table}.*")
            ->join($savesTable, function ($join) use ($table, $keyName, $savesTable, $morphClass) {
                $join->on("{$savesTable}.saver_id", '=', "{$table}.{$keyName}")
                    ->where("{$savesTable}.saver_type", '=', $morphClass);
            })
            ->where("{$savesTable}.saveable_type", $this->getMorphClass())
            ->where("{$savesTable}.saveable_id", $this->getKey())
            ->orderBy("{$savesTable}.created_at", 'desc');
    }

    protected function buildMixedTypesSaversQuery(array $types, string $savesTable): Builder
    {
        $firstModel = new $types[0];
        $builder = $firstModel->newQuery();
        $self = $this;

        $builder->macro('get', function ($columns = ['*']) use ($types, $savesTable, $self) {
            $allSavers = collect();

            foreach ($types as $type) {
                // Convert morph alias to class name if needed
                $modelClass = $self->getMorphClassFromType($type);

                if (!$modelClass) {
                    continue;
                }

                $results = $self->buildSingleTypeSaversQuery($type, $savesTable)->get($columns);
                $allSavers = $allSavers->merge($results);
            }

            return $allSavers->sortByDesc(function ($saver) use ($self) {
                return $self->saveRecords()
                    ->where('saver_type', $saver->getMorphClass())
                    ->where('saver_id', $saver->getKey())
                    ->value('created_at');
            })->values();
        });

        return $builder->whereRaw('1 = 1');
    }

    /**
     * Convert a morph alias to its full class name, or return the class name if already valid.
     * Works with both morphMap configured and non-configured scenarios.
     *
     * @param string $type The type (either a full class name or morph alias)
     * @return string|null The full class name if valid, null otherwise
     */
    protected function getMorphClassFromType(string $type): ?string
    {
        // If it's already a valid class, return it (non-morphMap case)
        if (class_exists($type)) {
            return $type;
        }

        // Try to get the mapped class from the morph alias (morphMap case)
        $morphedModel = Relation::getMorphedModel($type);
        if ($morphedModel !== null && class_exists($morphedModel)) {
            return $morphedModel;
        }

        return null;
    }
}
