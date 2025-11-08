<?php

namespace Ritechoice23\Saveable\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Collection extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('saveable.collections_table', 'collections');
    }

    protected static function booted(): void
    {
        // When a collection is deleted, delete all child collections
        static::deleting(function ($collection) {
            $collection->children()->get()->each->delete();

            // Set collection_id to null for all saves in this collection
            $collection->saves()->update(['collection_id' => null]);
        });
    }

    /**
     * Get the owner (who owns this collection).
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the parent collection.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'parent_id');
    }

    /**
     * Get the child collections (sub-folders).
     */
    public function children(): HasMany
    {
        return $this->hasMany(Collection::class, 'parent_id');
    }

    /**
     * Get all saves in this collection.
     */
    public function saves(): HasMany
    {
        return $this->hasMany(Save::class);
    }

    /**
     * Get the saved items (actual models) in this collection.
     * Returns a collection of polymorphic models.
     */
    public function items()
    {
        return $this->saves()->with('saveable')->get()->pluck('saveable');
    }

    /**
     * Scope: Filter collections by owner.
     */
    public function scopeByOwner($query, Model $owner)
    {
        return $query->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    /**
     * Scope: Get only root (top-level) collections.
     */
    public function scopeRootOnly($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: Get collections with a specific parent.
     */
    public function scopeByParent($query, ?Collection $parent)
    {
        if ($parent === null) {
            return $query->whereNull('parent_id');
        }

        return $query->where('parent_id', $parent->id);
    }
}
