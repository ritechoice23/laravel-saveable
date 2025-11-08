<?php

namespace Ritechoice23\Saveable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ritechoice23\Saveable\Models\Save;

class SaveFactory extends Factory
{
    protected $model = Save::class;

    public function definition(): array
    {
        return [
            'saver_type' => 'App\\Models\\User',
            'saver_id' => 1,
            'saveable_type' => 'App\\Models\\Post',
            'saveable_id' => 1,
            'collection_id' => null,
            'metadata' => [],
            'order_column' => 0,
        ];
    }
}
