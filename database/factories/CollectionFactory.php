<?php

namespace Ritechoice23\Saveable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ritechoice23\Saveable\Models\Collection;

class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'App\\Models\\User',
            'owner_id' => 1,
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'parent_id' => null,
        ];
    }
}
