<?php

namespace Ritechoice23\Saveable\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Save extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('saveable.saves_table', 'saves');
    }

    /**
     * Get the saver (who saved).
     */
    public function saver(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the saveable (what was saved).
     */
    public function saveable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the collection this save belongs to.
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * Scope: Filter saves by saver.
     */
    public function scopeBySaver($query, Model $saver)
    {
        return $query->where('saver_type', $saver->getMorphClass())
            ->where('saver_id', $saver->getKey());
    }

    /**
     * Scope: Filter saves by saveable.
     */
    public function scopeBySaveable($query, Model $saveable)
    {
        return $query->where('saveable_type', $saveable->getMorphClass())
            ->where('saveable_id', $saveable->getKey());
    }

    /**
     * Scope: Filter saves by collection.
     */
    public function scopeByCollection($query, ?Collection $collection)
    {
        if ($collection === null) {
            return $query->whereNull('collection_id');
        }

        return $query->where('collection_id', $collection->id);
    }

    /**
     * Scope: Get saves with a specific saveable type.
     */
    public function scopeWhereSaveableType($query, string $type)
    {
        return $query->where('saveable_type', $type);
    }

    /**
     * Scope: Order by custom order column.
     */
    public function scopeOrdered($query, string $direction = 'asc')
    {
        return $query->orderBy('order_column', $direction);
    }
}
