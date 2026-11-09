<?php

namespace Database\Factories;

use App\Models\Resource;
use App\Models\ResourceTag;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceTagFactory extends Factory
{
    protected $model = ResourceTag::class;

    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'tag' => fake()->word(),
            'created_at' => now(),
        ];
    }
}
